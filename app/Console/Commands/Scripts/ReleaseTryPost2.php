<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Media\AdoptWorkspaceLibrary;
use App\Actions\Media\AuditMedia;
use App\Actions\Post\DeleteChannelPosts;
use App\Actions\SocialAccount\ApplyChannelDefaults;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Media\AdoptWorkspaceLibraryJob;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The one-off data steps of the TryPost 2.0 release, in order. Every step is
 * safe to run again; the analytics backfill is dispatched once per install.
 *
 * Orphaned posts are purged first, so a post with a target on a deleted
 * channel keeps its id (the dead target is dropped instead of being split off)
 * and their library media is not copied only to be deleted. The split gives
 * every copy its own media whether or not the library was adopted already.
 *
 * Never rehearse it with `--force` on a database copy that points at the
 * production bucket: the adoption deletes library files no row of the copy
 * references, and production posts still use them. Give the copy its own
 * bucket (or a local disk), or rehearse with `--dry-run` only.
 */
#[Signature('release:trypost-2
    {--force : Run without confirmation in production}
    {--dry-run : Print what each step would do without changing anything (the only safe rehearsal against the production bucket)}
    {--backfill-chunk=100 : Accounts per analytics backfill batch}
    {--backfill-delay=0 : Seconds between analytics backfill batches}
    {--include-unsubscribed : Also backfill workspaces Cashier does not see as subscribed}
    {--rerun-backfill : Dispatch the analytics backfill again even if an earlier run already did}
    {--adoption-timeout=21600 : Seconds to wait for the queued library adoption before moving on (its jobs keep going)}')]
#[Description('Run the one-off data steps of the TryPost 2.0 release (safe to run again). Rehearse on a copy only with its own bucket, or with --dry-run')]
class ReleaseTryPost2 extends Command
{
    use ConfirmableTrait;

    public const int STEPS = 7;

    public const int ADOPTION_POLL_SECONDS = 15;

    public const string BACKFILL_DISPATCHED_KEY = 'release:trypost-2:backfill-dispatched-at';

    /** @var list<array{string, string, string}> */
    private array $summary = [];

    /** @var list<string> */
    private array $failedWorkspaces = [];

    public function handle(Migrator $migrator): int
    {
        $pending = $this->pendingMigrations($migrator);

        if ($pending !== []) {
            $count = count($pending);
            $this->error("{$count} pending migration(s). Run `php artisan migrate --force` (part of the deploy) first:");
            $this->line(collect($pending)->map(fn (string $name): string => "  {$name}")->implode(PHP_EOL));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $this->info('Baseline media audit (database and bucket listing only, read-only)...');
        $before = $this->snapshot();

        $chunk = max(1, (int) $this->option('backfill-chunk'));
        $delay = max(0, (int) $this->option('backfill-delay'));
        $unsubscribed = $this->option('include-unsubscribed') ? ' --include-unsubscribed' : '';
        $instagram = Platform::Instagram->value;
        $instagramFacebook = Platform::InstagramFacebook->value;

        $this->runReleaseStep('Purge posts of disconnected channels', 'posts:purge-orphaned', preview: function (): string {
            $plan = DeleteChannelPosts::orphanedPlan();

            return "would delete {$plan['deleted_posts']} post(s) and detach {$plan['detached_targets']} target(s)";
        });
        $this->runReleaseStep('Split legacy multi-target posts', 'posts:split-legacy-active', preview: function (): string {
            $this->call('posts:audit-legacy');

            return 'splits the "Editable" and "Settled" multiple-target posts above; in-flight ones stay';
        });

        if ($dryRun) {
            $this->runReleaseStep('Media library adoption', 'media:adopt-library --dry-run', readOnly: true);
        } else {
            $this->adoptLibrary();
        }

        $this->channelDefaults();
        $this->backfill("analytics:backfill-existing --chunk={$chunk} --delay={$delay}{$unsubscribed}");
        $this->runReleaseStep('Instagram stories discovery (queued)', "analytics:dispatch-publication-discovery --platform={$instagram} --platform={$instagramFacebook}");
        $unexpected = $this->audit($before);

        $this->newLine();
        $this->table(['Step', 'Status', 'Result'], $this->summary);

        if ($dryRun) {
            $this->info('Dry run: nothing was changed. The audit shows the state before the release.');

            return self::SUCCESS;
        }

        $failedSteps = collect($this->summary)->filter(fn (array $row): bool => $row[1] === 'failed')->count();

        if ($failedSteps > 0 || $unexpected > 0) {
            $this->error("Release finished with problems: {$failedSteps} failed step(s), {$unexpected} unexpected audit finding(s). Fix them and run the command again; finished steps are no-ops.");

            return self::FAILURE;
        }

        $this->info('Release steps done. Watch the analytics and media-imports queues in Horizon until they drain.');

        return self::SUCCESS;
    }

    /**
     * Queues one `AdoptWorkspaceLibraryJob` per workspace with library media
     * (its own queue, so Horizon adopts them in parallel), then waits until
     * every library is adopted, every remaining job has stopped, or
     * `--adoption-timeout` passes. A workspace still adopting at the timeout
     * keeps its job (it retries on its own); one whose job stopped with
     * library media left fails the step.
     */
    private function adoptLibrary(): void
    {
        $title = 'Media library adoption (queued)';
        $this->heading($title);

        $workspaces = Workspace::query()
            ->whereIn('id', Media::query()->where('collection', Media::LIBRARY_COLLECTION)->whereNotNull('workspace_id')->select('workspace_id'))
            ->orderBy('id')
            ->get();

        if ($workspaces->isEmpty()) {
            $this->line('  No library media to adopt.');
            $this->summary[] = [$title, 'ok', 'no library media'];

            return;
        }

        $total = $workspaces->count();

        if (! $this->queueAdoption($total)) {
            $this->failedWorkspaces = $workspaces->modelKeys();
            $this->summary[] = [$title, 'failed', "{$total} workspace(s) could not be queued, run media:adopt-library --force"];

            return;
        }

        $timeout = max(0, (int) $this->option('adoption-timeout'));
        $started = now();
        $deadline = $started->addSeconds($timeout);

        while (true) {
            $progress = $this->adoptionProgress($workspaces);
            $done = $total - count($progress['running']) - count($progress['stopped']);
            $stopped = count($progress['stopped']);
            $elapsed = (int) $started->diffInSeconds(now());
            $this->line("  {$done}/{$total} workspace(s) done, {$progress['rows']} library row(s) left, {$stopped} stopped ({$elapsed}s)");

            if ($progress['running'] === [] || now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            Sleep::for(self::ADOPTION_POLL_SECONDS)->seconds();
        }

        $running = count($progress['running']);
        $result = "{$done} of {$total} workspace(s) adopted in {$elapsed}s";

        if ($stopped > 0) {
            $this->failedWorkspaces = $progress['stopped'];
            $ids = implode(', ', $progress['stopped']);
            $this->error("  {$stopped} workspace(s) stopped with library media left: {$ids}. See the log, fix the cause and run media:adopt-library --force.");
        }

        if ($running > 0) {
            $this->warn("  {$running} workspace(s) still adopting after {$elapsed}s; their jobs keep going on the media-adoption queue.");
        }

        $this->summary[] = match (true) {
            $stopped > 0 => [$title, 'failed', "{$result}; {$stopped} workspace(s) stopped with library media left: ".implode(', ', $progress['stopped'])],
            $running > 0 => [$title, 'queued', "{$result}; {$running} workspace(s) still adopting on the queue"],
            default => [$title, 'ok', $result],
        };
    }

    /**
     * A workspace is done once every library row left is one another
     * workspace still uses (the foreign references `media:adopt-library`
     * cached); otherwise it is running while its job's unique lock is held,
     * and stopped once the job ended without finishing.
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @return array{rows: int, running: list<string>, stopped: list<string>}
     */
    private function adoptionProgress(Collection $workspaces): array
    {
        $kept = Cache::get(AdoptWorkspaceLibrary::FOREIGN_REFERENCES_CACHE_KEY, []);
        $left = Media::query()
            ->where('collection', Media::LIBRARY_COLLECTION)
            ->whereIn('workspace_id', $workspaces->modelKeys())
            ->toBase()
            ->get(['id', 'workspace_id'])
            ->reject(fn (object $row): bool => isset($kept[$row->workspace_id][$row->id]))
            ->groupBy('workspace_id');
        $progress = ['rows' => $left->flatten(1)->count(), 'running' => [], 'stopped' => []];

        foreach ($workspaces->whereIn('id', $left->keys()->all()) as $workspace) {
            $lock = Cache::lock(UniqueLock::getKey(new AdoptWorkspaceLibraryJob($workspace)), 1);
            $held = ! $lock->get();

            if (! $held) {
                $lock->release();
            }

            $progress[$held ? 'running' : 'stopped'][] = $workspace->id;
        }

        return $progress;
    }

    /**
     * Dispatches the adoption jobs through `media:adopt-library` (which
     * skips workspaces whose job is still queued); false when that fails.
     */
    private function queueAdoption(int $count): bool
    {
        $this->line("  Queueing AdoptWorkspaceLibraryJob for {$count} workspace(s) on the media-adoption queue.");

        try {
            $this->call('media:adopt-library', ['--force' => true]);

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $this->error("  Could not queue AdoptWorkspaceLibraryJob: {$exception->getMessage()}");

            return false;
        }
    }

    /**
     * Channels connected before posting schedules existed get what a new
     * channel gets (owner time zone, goal 3, the network's recommended slots);
     * a channel that already has a schedule is never touched.
     */
    private function channelDefaults(): void
    {
        $title = 'Channel posting defaults';
        $this->heading($title);

        if ($this->option('dry-run')) {
            $pending = ApplyChannelDefaults::pending();
            $this->line("  Would set up {$pending} channel(s) without a posting schedule.");
            $this->summary[] = [$title, 'dry run', "would set up {$pending} channel(s)"];

            return;
        }

        try {
            $count = ApplyChannelDefaults::backfill();
        } catch (Throwable $exception) {
            report($exception);
            $this->error("  Failed: {$exception->getMessage()}");
            $this->summary[] = [$title, 'failed', $exception->getMessage()];

            return;
        }

        $this->line("  Set up {$count} channel(s) without a posting schedule.");
        $this->summary[] = [$title, 'ok', "{$count} channel(s) set up"];
    }

    /**
     * Dispatched once per install: its jobs may wait hours behind
     * `--backfill-delay`, and a second dispatch before they start would read
     * every account twice (X bills per read).
     */
    private function backfill(string $command): void
    {
        $title = 'Analytics backfill and external posts import (queued)';
        $dispatchedAt = Cache::store('database')->get(self::BACKFILL_DISPATCHED_KEY);

        if ($dispatchedAt !== null && ! $this->option('rerun-backfill')) {
            $this->heading($title);
            $this->line("  Skipped: already dispatched at {$dispatchedAt}. Pass --rerun-backfill to dispatch it again.");
            $this->summary[] = [$title, 'skipped', "already dispatched at {$dispatchedAt}"];

            return;
        }

        if ($this->runReleaseStep($title, $command) && ! $this->option('dry-run')) {
            Cache::store('database')->forever(self::BACKFILL_DISPATCHED_KEY, now()->toIso8601String());
        }
    }

    /**
     * The state the final audit is compared with: the audit without its
     * per-row storage requests (only the final audit checks every file, after
     * the data steps), and the rows that existed before the release.
     *
     * @return array{report: array<string, list<array<string, string>>>, ids: array<string, true>, paths: array<string, true>, dangling: array<string, true>}
     */
    private function snapshot(): array
    {
        $report = AuditMedia::execute(checkMissingFiles: false);
        $ids = [];
        $paths = [];
        $dangling = [];

        Media::query()->toBase()->select(['id', 'path', 'collection'])->chunkById(AuditMedia::CHUNK, function ($rows) use (&$ids, &$paths, &$dangling): void {
            foreach ($rows as $row) {
                $ids[$row->id] = true;
                $paths[$row->path] = true;

                if ($row->collection === Media::LIBRARY_COLLECTION) {
                    $dangling[$row->id] = true;
                }
            }
        });

        foreach ($report['json_drift'] as $finding) {
            if ($finding['problem'] === 'json_without_row') {
                $dangling[$finding['media_id']] = true;
            }
        }

        return ['report' => $report, 'ids' => $ids, 'paths' => $paths, 'dangling' => $dangling];
    }

    /**
     * Compares the final media audit with the snapshot. A finding is expected
     * when it was already there; when an item points at a library row the
     * adoption kept (another workspace uses it, or it was left to the queued
     * job); when an item points at a row that is gone but was a library row or
     * already dangling before the release; when a file without a row belonged
     * to a row before the release (its deletion is queued); or when a row from
     * before the release has no file (the snapshot does not check files).
     * Anything else, such as a referenced row the release deleted, is
     * unexpected.
     *
     * @param  array{report: array<string, list<array<string, string>>>, ids: array<string, true>, paths: array<string, true>, dangling: array<string, true>}  $before
     */
    private function audit(array $before): int
    {
        $title = 'Media audit (read-only)';
        $this->heading($title);

        $started = microtime(true);
        $report = $this->option('dry-run') ? $before['report'] : AuditMedia::execute();
        $seconds = number_format(microtime(true) - $started, 1);
        $fingerprint = fn (array $finding): string => json_encode(collect($finding)->sortKeys()->all());
        $known = collect($before['report'])->map(fn (array $findings): array => array_flip(array_map($fingerprint, $findings)))->all();
        $collections = collect($report['json_drift'] ?? [])
            ->pluck('media_id')
            ->filter(fn (string $id): bool => Str::isUuid($id))
            ->chunk(1000)
            ->flatMap(fn ($ids) => Media::query()->whereIn('id', $ids->all())->pluck('collection', 'id'))
            ->all();

        $isExpected = fn (string $check, array $finding): bool => isset($known[$check][$fingerprint($finding)])
            || ($check === 'json_drift' && $finding['problem'] === 'json_without_row' && match ($collections[$finding['media_id']] ?? null) {
                null => isset($before['dangling'][$finding['media_id']]),
                Media::LIBRARY_COLLECTION => true,
                default => false,
            })
            || ($check === 'orphaned_files' && isset($before['paths'][$finding['path']]))
            || ($check === 'missing_files' && isset($before['ids'][$finding['media_id']]));

        $rows = [];
        $unexpected = [];

        foreach ($report as $check => $findings) {
            $surprises = array_values(array_filter($findings, fn (array $finding): bool => ! $isExpected($check, $finding)));
            $rows[] = [$check, count($findings) - count($surprises), count($surprises)];

            foreach ($surprises as $finding) {
                $unexpected[] = "{$check}: ".collect($finding)->map(fn (string $value, string $key): string => "{$key}={$value}")->implode(' ');
            }
        }

        $this->table(['Check', 'Expected', 'Unexpected'], $rows);

        foreach ($unexpected as $line) {
            $this->line("  unexpected {$line}");
        }

        $expected = collect($rows)->sum(1);
        $count = count($unexpected);
        $status = match (true) {
            $this->option('dry-run') => 'dry run',
            $count === 0 => 'ok',
            default => 'findings',
        };
        $this->summary[] = [$title, $status, "{$expected} expected, {$count} unexpected in {$seconds}s"];

        return $count;
    }

    /**
     * Runs one existing command, echoing its output. In a dry run a write
     * step only prints what it would do (and its preview); a read-only one
     * runs.
     *
     * @param  (callable(): string)|null  $preview
     */
    private function runReleaseStep(string $title, string $command, bool $readOnly = false, ?callable $preview = null): bool
    {
        $this->heading($title);

        if ($this->option('dry-run') && ! $readOnly) {
            $this->line("  Would run: php artisan {$command}");
            $detail = $preview !== null ? $preview() : "php artisan {$command}";
            $this->line("  {$detail}");
            $this->summary[] = [$title, 'dry run', $detail];

            return true;
        }

        $buffer = new BufferedOutput;

        try {
            $code = Artisan::call($command, [], $buffer);
        } catch (Throwable $exception) {
            report($exception);
            $this->output->write($buffer->fetch());
            $this->error("  Failed: {$exception->getMessage()}");
            $this->summary[] = [$title, 'failed', $exception->getMessage()];

            return false;
        }

        $output = $buffer->fetch();
        $this->output->write($output);

        $digest = collect(explode(PHP_EOL, $output))
            ->map(fn (string $line): string => trim($line))
            ->reject(fn (string $line): bool => $line === '' || Str::startsWith($line, ['+', '|']))
            ->implode('; ');

        $status = match (true) {
            $code !== self::SUCCESS => 'failed',
            $this->option('dry-run') => 'dry run',
            default => 'ok',
        };
        $this->summary[] = [$title, $status, $digest !== '' ? $digest : 'done'];

        return $code === self::SUCCESS;
    }

    private function heading(string $title): void
    {
        $step = count($this->summary) + 1;
        $steps = self::STEPS;

        $this->newLine();
        $this->info("[{$step}/{$steps}] {$title}");
    }

    /**
     * @return list<string>
     */
    private function pendingMigrations(Migrator $migrator): array
    {
        if (! $migrator->repositoryExists()) {
            return ['migrations table'];
        }

        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);

        return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Media\AdoptWorkspaceLibrary;
use App\Jobs\Media\AdoptWorkspaceLibraryJob;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

#[Signature('media:adopt-library
    {--force : Run without confirmation in production}
    {--dry-run : Print per-workspace counts without dispatching anything}')]
#[Description('Copy library media onto the posts and ideas that use it, then delete the library')]
class AdoptLibraryCommand extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        $workspaceIds = Media::query()
            ->where('collection', Media::LIBRARY_COLLECTION)
            ->whereNotNull('workspace_id')
            ->distinct()
            ->pluck('workspace_id')
            ->all();

        if ($workspaceIds === []) {
            if (! $this->option('dry-run')) {
                Cache::forget(AdoptWorkspaceLibrary::FOREIGN_REFERENCES_CACHE_KEY);
            }

            $this->info('No library media to adopt.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $rows = Workspace::query()->whereKey($workspaceIds)->get()
                ->map(function (Workspace $workspace): array {
                    $plan = AdoptWorkspaceLibrary::plan($workspace);

                    return [$workspace->id, $workspace->name, $plan['library'], $plan['references'], $plan['orphans'], $plan['missing'], $plan['publishing']];
                })
                ->all();

            $this->table(['Workspace', 'Name', 'Library rows', 'References to copy', 'Orphans to delete', 'Missing files', 'Publishing posts (defer)'], $rows);

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $foreignReferences = Cache::remember(
            AdoptWorkspaceLibrary::FOREIGN_REFERENCES_CACHE_KEY,
            AdoptWorkspaceLibraryJob::UNIQUE_FOR_SECONDS,
            fn (): array => AdoptWorkspaceLibrary::foreignReferences(),
        );
        $lock = new UniqueLock(Cache::store());
        $dispatched = 0;
        $alreadyQueued = 0;

        foreach (Workspace::query()->whereKey($workspaceIds)->get() as $workspace) {
            $job = new AdoptWorkspaceLibraryJob($workspace, $foreignReferences[$workspace->id] ?? []);

            if (! $lock->acquire($job)) {
                $alreadyQueued++;

                continue;
            }

            Bus::dispatch($job);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} library adoption job(s); {$alreadyQueued} already queued.");

        return self::SUCCESS;
    }
}

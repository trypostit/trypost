<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BackfillTryPostPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Account;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('analytics:backfill-existing {--workspace= : Limit rollout to one workspace UUID} {--include-unsubscribed : Explicitly include workspaces not recognized as subscribed by Cashier} {--platforms= : Comma-separated platforms to include, e.g. instagram,facebook} {--chunk=100 : Accounts per dispatch batch} {--delay=0 : Seconds between batches}')]
#[Description('Manually dispatch historical analytics backfills, which also import external posts, for existing accounts and publications')]
class BackfillExistingAnalytics extends Command
{
    public function handle(): int
    {
        $platforms = $this->platforms();

        if ($platforms === null) {
            return self::FAILURE;
        }

        $workspaceId = $this->option('workspace');
        $includeUnsubscribed = (bool) $this->option('include-unsubscribed');
        $batchSize = max(1, (int) $this->option('chunk'));
        $delay = max(0, (int) $this->option('delay'));
        $staleBefore = CarbonImmutable::now('UTC')->subHours(2);
        $date = CarbonImmutable::now('UTC')->toDateString();
        $orphaned = 0;
        $linked = 0;
        $accounts = 0;
        $imported = 0;

        Workspace::query()
            ->with('account.subscriptions')
            ->when($workspaceId, fn (Builder $query): Builder => $query->whereKey($workspaceId))
            ->reorder()
            ->lazyById(100)
            ->filter(fn (Workspace $workspace): bool => $includeUnsubscribed || $workspace->account->subscribed(Account::SUBSCRIPTION_NAME))
            ->chunk(100)
            ->each(function ($workspaces) use ($batchSize, $date, $delay, $platforms, $staleBefore, &$accounts, &$imported, &$linked, &$orphaned): void {
                $ids = $workspaces->pluck('id')->all();
                $inWorkspaces = fn (Builder $post): Builder => $post->whereIn('workspace_id', $ids);
                $onPlatforms = fn (Builder $query, string $column): Builder => $query->when($platforms !== [], fn (Builder $scoped): Builder => $scoped->whereIn($column, $platforms));

                $onPlatforms(PostPlatform::query(), 'post_platforms.platform')
                    ->published()
                    ->includedInAnalytics()
                    ->whereNotNull('social_account_id')
                    ->whereDoesntHave('analyticsPublication')
                    ->whereHas('post', $inWorkspaces)
                    ->lazyById(100)
                    ->chunk(100)
                    ->each(function ($chunk) use (&$linked): void {
                        BackfillTryPostPublications::dispatch($chunk->pluck('id')->map(fn ($id): string => (string) $id)->values()->all());
                        $linked += $chunk->count();
                    });

                $orphaned += $onPlatforms(PostPlatform::query(), 'post_platforms.platform')
                    ->published()
                    ->includedInAnalytics()
                    ->whereNull('social_account_id')
                    ->whereHas('post', $inWorkspaces)
                    ->count();

                $onPlatforms(SocialAccount::query(), 'platform')
                    ->connected()
                    ->includedInAnalytics()
                    ->whereIn('workspace_id', $ids)
                    ->where(function (Builder $query) use ($staleBefore): void {
                        $query->whereDoesntHave('analyticsSyncStates', fn (Builder $states): Builder => $states->forCollector(SyncCollector::PublicationBackfill))
                            ->orWhereHas('analyticsSyncStates', fn (Builder $states): Builder => $states
                                ->forCollector(SyncCollector::PublicationBackfill)
                                ->where(function (Builder $state): void {
                                    $state->whereIn('status', [SyncStatus::Pending, SyncStatus::Running])
                                        ->orWhere(fn (Builder $failed): Builder => $failed
                                            ->where('status', SyncStatus::Failed)
                                            ->where('last_error_category', 'queue_failed'));
                                })
                                ->where('updated_at', '<', $staleBefore));
                    })
                    ->with(['analyticsSyncStates' => fn ($query) => $query->forCollector(SyncCollector::PublicationBackfill)])
                    ->lazyById(100)
                    ->each(function (SocialAccount $account) use ($batchSize, $date, $delay, &$accounts): void {
                        $wait = intdiv($accounts, $batchSize) * $delay;

                        if ($account->analyticsSyncStates->isEmpty()) {
                            CollectAccountDailySnapshot::dispatch($account->id, $date)->delay($wait);
                        }

                        BootstrapAccountAnalytics::dispatch($account->id)->delay($wait);
                        $accounts++;
                    });

                $imported += Post::query()
                    ->imported()
                    ->whereIn('workspace_id', $ids)
                    ->when($platforms !== [], fn (Builder $query): Builder => $query->whereHas('postPlatforms', fn (Builder $targets): Builder => $targets->whereIn('platform', $platforms)))
                    ->count();
            });

        $this->info("accounts_dispatched={$accounts}");
        $this->info("publications_to_link={$linked}");
        $this->info("posts_imported={$imported}");
        $this->info("historical_identity_unrecoverable={$orphaned}");

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function platforms(): ?array
    {
        $values = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('platforms')))));
        $unknown = array_values(array_filter($values, fn (string $value): bool => Platform::tryFrom($value) === null));

        if ($unknown !== []) {
            $this->error('Unknown platforms: '.implode(', ', $unknown));

            return null;
        }

        return $values;
    }
}

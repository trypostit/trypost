<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Jobs\Analytics\BackfillTryPostPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Account;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('analytics:backfill-existing {--workspace= : Limit rollout to one workspace UUID} {--include-unsubscribed : Explicitly include workspaces not recognized as subscribed by Cashier}')]
#[Description('Manually dispatch historical analytics backfills for existing accounts and publications')]
class BackfillExistingAnalytics extends Command
{
    public function handle(): int
    {
        $workspaceId = $this->option('workspace');
        $includeUnsubscribed = (bool) $this->option('include-unsubscribed');
        $staleBefore = CarbonImmutable::now('UTC')->subHours(2);
        $date = CarbonImmutable::now('UTC')->toDateString();
        $orphaned = 0;

        Workspace::query()
            ->with('account.subscriptions')
            ->when($workspaceId, fn (Builder $query): Builder => $query->whereKey($workspaceId))
            ->reorder()
            ->lazyById(100)
            ->filter(fn (Workspace $workspace): bool => $includeUnsubscribed || $workspace->account->subscribed(Account::SUBSCRIPTION_NAME))
            ->chunk(100)
            ->each(function ($workspaces) use ($date, $staleBefore, &$orphaned): void {
                $workspaceIds = $workspaces->pluck('id')->all();
                $inWorkspaces = fn (Builder $post): Builder => $post->whereIn('workspace_id', $workspaceIds);

                PostPlatform::query()
                    ->published()
                    ->includedInAnalytics()
                    ->whereNotNull('social_account_id')
                    ->whereDoesntHave('analyticsPublication')
                    ->whereHas('post', $inWorkspaces)
                    ->lazyById(100)
                    ->chunk(100)
                    ->each(fn ($chunk) => BackfillTryPostPublications::dispatch(
                        $chunk->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
                    ));

                $orphaned += PostPlatform::query()
                    ->published()
                    ->includedInAnalytics()
                    ->whereNull('social_account_id')
                    ->whereHas('post', $inWorkspaces)
                    ->count();

                SocialAccount::query()
                    ->connected()
                    ->active()
                    ->includedInAnalytics()
                    ->whereIn('workspace_id', $workspaceIds)
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
                    ->reorder()
                    ->lazyById(100)
                    ->each(function (SocialAccount $account) use ($date): void {
                        if ($account->analyticsSyncStates->isEmpty()) {
                            CollectAccountDailySnapshot::dispatch($account->id, $date);
                        }

                        BootstrapAccountAnalytics::dispatch($account->id);
                    });
            });

        $this->info("historical_identity_unrecoverable={$orphaned}");

        return self::SUCCESS;
    }
}

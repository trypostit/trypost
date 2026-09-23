<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Jobs\Analytics\BackfillTryPostPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('analytics:backfill-existing {--workspace= : Limit rollout to one workspace UUID} {--include-unsubscribed : Explicitly include workspaces without an active paid subscription}')]
#[Description('Dispatch resumable analytics backfills for existing accounts and publications')]
class BackfillExistingAnalytics extends Command
{
    public function handle(): int
    {
        $workspaceId = $this->option('workspace');
        $includeUnsubscribed = (bool) $this->option('include-unsubscribed');
        $paidAccount = fn (Builder $account): Builder => $account->withActivePaidSubscription();
        $destinations = PostPlatform::query()
            ->published()
            ->includedInAnalytics()
            ->whereNotNull('social_account_id')
            ->whereDoesntHave('analyticsPublication')
            ->when(! $includeUnsubscribed, fn (Builder $query): Builder => $query->whereHas('post.workspace.account', $paidAccount))
            ->when($workspaceId, fn ($query) => $query->whereHas(
                'post',
                fn ($post) => $post->where('workspace_id', $workspaceId),
            ));

        $destinations->lazyById(100)
            ->chunk(100)
            ->each(fn ($chunk) => BackfillTryPostPublications::dispatch(
                $chunk->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            ));

        $orphaned = PostPlatform::query()
            ->published()
            ->includedInAnalytics()
            ->whereNull('social_account_id')
            ->when(! $includeUnsubscribed, fn (Builder $query): Builder => $query->whereHas('post.workspace.account', $paidAccount))
            ->when($workspaceId, fn ($query) => $query->whereHas(
                'post',
                fn ($post) => $post->where('workspace_id', $workspaceId),
            ))
            ->count();

        $staleBefore = CarbonImmutable::now('UTC')->subHours(2);
        $date = CarbonImmutable::now('UTC')->toDateString();

        SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->when(! $includeUnsubscribed, fn (Builder $query): Builder => $query->whereHas('workspace.account', $paidAccount))
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
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

        $this->info("historical_identity_unrecoverable={$orphaned}");

        return self::SUCCESS;
    }
}

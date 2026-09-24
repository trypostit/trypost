<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class BootstrapAccountAnalytics implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public string $socialAccountId, public bool $refreshOnTerminal = false)
    {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        $this->handleFor($this->socialAccountId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Analytics account bootstrap failed', [
            'social_account_id' => $this->socialAccountId,
            'exception' => $exception,
        ]);
    }

    public function handleFor(string $socialAccountId): void
    {
        $account = SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->find($socialAccountId);

        if (! $account || ! app(PublicationHistoryCollectorFactory::class)->supports($account->platform)) {
            return;
        }

        [$backfill, $backfillRebound] = $this->stateFor($account, SyncCollector::PublicationBackfill, [
            'status' => SyncStatus::Pending,
            'checkpoint' => ['cursor' => null, 'revision' => 0],
            'target_since' => CarbonImmutable::now('UTC')->subDays(365),
        ]);

        $historicalNewest = null;
        $recoveredWithoutCheckpoint = false;

        if ($backfill->wasRecentlyCreated) {
            $historicalPublications = AnalyticsPublication::query()
                ->where(AnalyticsSyncState::providerIdentityFor($account))
                ->where('origin', PublicationOrigin::External)
                ->whereNull('social_account_id');
            $historicalNewest = $historicalPublications->max('provider_published_at');
            $recoveredWithoutCheckpoint = $historicalNewest !== null;
        }

        if ($recoveredWithoutCheckpoint) {
            $backfill->update([
                'status' => SyncStatus::Partial,
                'oldest_reached_at' => $historicalPublications->min('provider_published_at'),
                'high_watermark_at' => $historicalNewest,
                'last_error_category' => 'prior_checkpoint_unavailable',
            ]);
        }

        [$discovery] = $this->stateFor($account, SyncCollector::PublicationDiscovery, [
            'status' => SyncStatus::Pending,
            'checkpoint' => ['cursor' => null, 'revision' => 0],
        ]);

        if ($recoveredWithoutCheckpoint && ! $discovery->high_watermark_at) {
            $discovery->update(['high_watermark_at' => $historicalNewest]);
        }

        if ($backfillRebound || $recoveredWithoutCheckpoint) {
            AnalyticsPublication::query()
                ->where(AnalyticsSyncState::providerIdentityFor($account))
                ->where('platform', $account->platform)
                ->whereNull('social_account_id')
                ->update(['social_account_id' => $account->id]);
        }

        $resumeFailed = $backfill->status === SyncStatus::Failed;
        $retryPartial = $backfill->status === SyncStatus::Partial
            && ! $backfillRebound
            && ! $recoveredWithoutCheckpoint
            && $backfill->last_error_category !== 'prior_checkpoint_unavailable';

        if ($resumeFailed || $retryPartial) {
            $backfill->update([
                'status' => SyncStatus::Pending,
                'checkpoint' => [
                    'cursor' => $resumeFailed
                        ? data_get($backfill->checkpoint, 'cursor')
                        : null,
                    'revision' => (int) data_get($backfill->checkpoint, 'revision', 0),
                    ...($account->platform === Platform::X
                        ? ['seen_count' => $resumeFailed
                            ? (int) data_get($backfill->checkpoint, 'seen_count', 0)
                            : 0]
                        : []),
                    ...($resumeFailed && data_get($backfill->checkpoint, 'resumed_after_disconnect')
                        ? ['resumed_after_disconnect' => true]
                        : []),
                ],
            ]);
        }

        if (! $backfill->isTerminal()) {
            BackfillAccountPublications::dispatch($account->id, $backfill->id)->afterCommit();
        } elseif ($backfillRebound || $recoveredWithoutCheckpoint || $this->refreshOnTerminal) {
            DiscoverAccountPublications::dispatch($account->id, $discovery->id)->afterCommit();
        }
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array{AnalyticsSyncState, bool}
     */
    private function stateFor(SocialAccount $account, SyncCollector $collector, array $defaults): array
    {
        $identity = AnalyticsSyncState::identityFor($account);
        $state = AnalyticsSyncState::query()
            ->where('social_account_id', $account->id)
            ->forCollector($collector)
            ->first()
            ?? AnalyticsSyncState::query()
                ->forIdentity($account)
                ->forCollector($collector)
                ->first()
            ?? AnalyticsSyncState::query()->firstOrCreate(
                [...$identity, 'collector' => $collector],
                ['social_account_id' => $account->id, ...$defaults],
            );

        $rebound = $state->social_account_id !== $account->id;

        if ($rebound
            || $state->workspace_id !== $identity['workspace_id']
            || $state->network !== $identity['network']
            || $state->platform_user_id !== $identity['platform_user_id']
            || $state->identity_key !== $identity['identity_key']) {
            $checkpoint = $state->checkpoint ?? [];
            $state->update([
                ...$identity,
                'social_account_id' => $account->id,
                'status' => $rebound && $state->status === SyncStatus::Running
                    ? SyncStatus::Pending
                    : $state->status,
                'checkpoint' => $rebound ? [
                    ...$checkpoint,
                    'cursor' => $collector === SyncCollector::PublicationDiscovery
                        ? null
                        : data_get($checkpoint, 'cursor'),
                    'revision' => (int) data_get($checkpoint, 'revision', 0) + 1,
                    ...($collector === SyncCollector::PublicationBackfill
                        && filled(data_get($checkpoint, 'cursor'))
                        && $state->oldest_reached_at !== null
                        ? ['resumed_after_disconnect' => true]
                        : []),
                ] : $checkpoint,
            ]);
        }

        return [$state, $rebound];
    }
}

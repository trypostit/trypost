<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Support\ChannelPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class BootstrapAccountAnalytics implements ShouldQueue
{
    use Queueable;

    private const string PRIOR_CHECKPOINT_UNAVAILABLE = 'prior_checkpoint_unavailable';

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
                'last_error_category' => self::PRIOR_CHECKPOINT_UNAVAILABLE,
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

        if (! ChannelPermissions::permits($account, GatedFeature::PostHistory)) {
            app(AdvanceAnalyticsSyncState::class)->pause($backfill->id, $account->id);

            return;
        }

        $this->retryBackfill($backfill, $account->platform, canRestartPartial: ! $backfillRebound && ! $recoveredWithoutCheckpoint);

        if (! $backfill->isTerminal()) {
            BackfillAccountPublications::dispatch($account->id, $backfill->id)->afterCommit();

            return;
        }

        if ($backfillRebound || $recoveredWithoutCheckpoint || $this->refreshOnTerminal) {
            DiscoverAccountPublications::dispatch($account->id, $discovery->id)->afterCommit();
        }
    }

    private function retryBackfill(AnalyticsSyncState $backfill, Platform $platform, bool $canRestartPartial): void
    {
        $resumeFailed = $backfill->status === SyncStatus::Failed;
        $restartPartial = $canRestartPartial
            && $backfill->status === SyncStatus::Partial
            && $backfill->last_error_category !== self::PRIOR_CHECKPOINT_UNAVAILABLE;

        if (! $resumeFailed && ! $restartPartial) {
            return;
        }

        $previous = $backfill->checkpoint ?? [];
        $checkpoint = [
            'cursor' => $resumeFailed ? data_get($previous, 'cursor') : null,
            'revision' => (int) data_get($previous, 'revision', 0),
        ];

        if ($platform === Platform::X) {
            $checkpoint['seen_count'] = $resumeFailed ? (int) data_get($previous, 'seen_count', 0) : 0;
        }

        if ($resumeFailed && data_get($previous, 'public_history_only')) {
            $checkpoint['public_history_only'] = true;
        }

        if ($resumeFailed && data_get($previous, 'resumed_after_disconnect')) {
            $checkpoint['resumed_after_disconnect'] = true;
        }

        $backfill->update([
            'status' => SyncStatus::Pending,
            'checkpoint' => $checkpoint,
        ]);
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

        if (! $rebound && $state->only(array_keys($identity)) === $identity) {
            return [$state, false];
        }

        $checkpoint = $state->checkpoint ?? [];
        $status = $state->status;

        if ($rebound) {
            $status = $this->statusAfterReconnect($state, $account);
            $cursor = data_get($checkpoint, 'cursor');
            $checkpoint['cursor'] = $collector === SyncCollector::PublicationDiscovery ? null : $cursor;
            $checkpoint['revision'] = (int) data_get($checkpoint, 'revision', 0) + 1;

            if ($collector === SyncCollector::PublicationBackfill && filled($cursor) && $state->oldest_reached_at !== null) {
                $checkpoint['resumed_after_disconnect'] = true;
            }
        }

        $state->update([
            ...$identity,
            'social_account_id' => $account->id,
            'status' => $status,
            'checkpoint' => $checkpoint,
        ]);

        return [$state, $rebound];
    }

    private function statusAfterReconnect(AnalyticsSyncState $state, SocialAccount $account): SyncStatus
    {
        if ($state->status === SyncStatus::Running) {
            return SyncStatus::Pending;
        }

        if ($this->shouldRetryLegacyTikTokBackfill($state, $account)) {
            return SyncStatus::Paused;
        }

        return $state->status;
    }

    /**
     * Prefer the saved grant at disconnect. When an older checkpoint lacks it,
     * retry an ambiguous limit only if the backfill never reached a publication.
     */
    private function shouldRetryLegacyTikTokBackfill(AnalyticsSyncState $state, SocialAccount $account): bool
    {
        if ($account->platform !== Platform::TikTok || $state->collector !== SyncCollector::PublicationBackfill
            || $state->status !== SyncStatus::ProviderLimited || $state->last_error_category !== 'provider_limited') {
            return false;
        }

        return data_get($state->checkpoint, 'missing_history_grant')
            ?? ($state->oldest_reached_at === null && $state->high_watermark_at === null);
    }
}

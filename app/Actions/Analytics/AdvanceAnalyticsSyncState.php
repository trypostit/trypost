<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\PublicationPage;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AdvanceAnalyticsSyncState
{
    private const X_TIMELINE_LIMIT = 3200;

    public function __construct(
        private readonly UpsertAnalyticsPublication $publications,
        private readonly ResolveAnalyticsAccountKey $accountKeys,
    ) {}

    /**
     * @return array{cursor: ?string, revision: int, cutoff: CarbonImmutable}|null
     */
    public function begin(string $stateId, bool $restartTerminal = false, ?string $socialAccountId = null): ?array
    {
        return DB::transaction(function () use ($restartTerminal, $socialAccountId, $stateId): ?array {
            $state = AnalyticsSyncState::query()->lockForUpdate()->find($stateId);

            if (! $state
                || ($socialAccountId !== null && $state->social_account_id !== $socialAccountId)
                || ($state->isTerminal() && ! $restartTerminal)) {
                return null;
            }

            $checkpoint = $state->checkpoint ?? [];
            $revision = ((int) ($checkpoint['revision'] ?? 0)) + 1;
            $cursor = $restartTerminal && $state->isTerminal()
                ? null
                : ($checkpoint['cursor'] ?? null);
            $seenCount = $restartTerminal && $state->isTerminal()
                ? 0
                : (int) ($checkpoint['seen_count'] ?? 0);

            $state->update([
                'status' => SyncStatus::Running,
                'checkpoint' => [
                    'cursor' => $cursor,
                    'revision' => $revision,
                    ...($state->collector === SyncCollector::PublicationBackfill && $state->socialAccount?->platform === Platform::X
                        ? ['seen_count' => $seenCount]
                        : []),
                    ...(! empty($checkpoint['resumed_after_disconnect'])
                        ? ['resumed_after_disconnect' => true]
                        : []),
                    ...(! empty($checkpoint['had_provider_limit'])
                        ? ['had_provider_limit' => true]
                        : []),
                    ...(! $restartTerminal && ! empty($checkpoint['invalid_cursor_resets'])
                        ? ['invalid_cursor_resets' => (int) $checkpoint['invalid_cursor_resets']]
                        : []),
                ],
                'last_error_category' => null,
            ]);

            $cutoff = $state->collector === SyncCollector::PublicationBackfill
                ? ($state->target_since ?? CarbonImmutable::now('UTC')->subDays(365))
                : ($state->high_watermark_at ?? CarbonImmutable::now('UTC'))->subDays(3);

            return [
                'cursor' => is_string($cursor) && $cursor !== '' ? $cursor : null,
                'revision' => $revision,
                'cutoff' => $cutoff->toImmutable(),
            ];
        });
    }

    /**
     * Persist page facts even for a stale worker, but only let the worker that
     * owns the current revision advance the provider cursor.
     *
     * @return array{advanced: bool, terminal: bool}
     */
    public function handle(
        string $stateId,
        int $capturedRevision,
        SocialAccount $account,
        PublicationPage $page,
    ): array {
        return DB::transaction(function () use ($account, $capturedRevision, $page, $stateId): array {
            $state = AnalyticsSyncState::query()->lockForUpdate()->find($stateId);

            if (! $state || $state->social_account_id !== $account->id) {
                return ['advanced' => false, 'terminal' => true];
            }

            $identity = $page->publications === [] ? null : TryPostPublicationIdentity::fromAccount(
                $account,
                $this->accountKeys->for($account),
            );

            foreach ($page->publications as $publication) {
                $this->publications->external($account, $publication, $identity);
            }

            $checkpoint = $state->checkpoint ?? [];

            if ((int) ($checkpoint['revision'] ?? 0) !== $capturedRevision) {
                return ['advanced' => false, 'terminal' => $state->isTerminal()];
            }

            $publishedAt = collect($page->publications)->pluck('publishedAt');
            $pageOldest = $publishedAt->min();
            $pageNewest = $publishedAt->max();
            $oldest = $this->earlier($state->oldest_reached_at, $pageOldest);
            $highWatermark = $this->later($state->high_watermark_at, $pageNewest);
            $reachedTarget = $state->collector === SyncCollector::PublicationBackfill
                && $page->canStopAtTarget
                && $oldest
                && $state->target_since
                && $oldest->lessThanOrEqualTo($state->target_since);
            $isXBackfill = $state->collector === SyncCollector::PublicationBackfill
                && $account->platform === Platform::X;
            $seenCount = (int) ($checkpoint['seen_count'] ?? 0) + count($page->publications);
            $xTimelineLimited = $isXBackfill
                && $page->providerExhausted
                && ! $reachedTarget
                && $state->target_since
                && $oldest
                && $oldest->greaterThan($state->target_since)
                && $seenCount >= self::X_TIMELINE_LIMIT;
            $hadProviderLimit = $page->providerLimited || ! empty($checkpoint['had_provider_limit']);
            $finished = $page->providerExhausted || $reachedTarget;

            $status = match (true) {
                $xTimelineLimited, $hadProviderLimit && $finished => SyncStatus::ProviderLimited,
                filled($page->partialReason) && $finished => SyncStatus::Partial,
                $finished => SyncStatus::Complete,
                default => SyncStatus::Running,
            };

            $state->update([
                'status' => $status,
                'checkpoint' => [
                    'cursor' => $status === SyncStatus::Running ? $page->nextCursor : null,
                    'revision' => $capturedRevision,
                    ...($isXBackfill ? ['seen_count' => $seenCount] : []),
                    ...($hadProviderLimit && $status === SyncStatus::Running ? ['had_provider_limit' => true] : []),
                    ...($status === SyncStatus::Running && ! empty($checkpoint['invalid_cursor_resets'])
                        ? ['invalid_cursor_resets' => (int) $checkpoint['invalid_cursor_resets']]
                        : []),
                ],
                'oldest_reached_at' => $oldest,
                'high_watermark_at' => $highWatermark,
                'last_success_at' => CarbonImmutable::now('UTC'),
                'last_error_category' => match (true) {
                    $hadProviderLimit => 'provider_limited',
                    $xTimelineLimited => 'x_timeline_3200',
                    default => $page->partialReason,
                },
            ]);

            if ($state->collector === SyncCollector::PublicationBackfill && $status !== SyncStatus::Running) {
                $this->initializeDiscovery($account, $highWatermark);
            }

            return ['advanced' => true, 'terminal' => $status !== SyncStatus::Running];
        });
    }

    public function recordFailure(string $stateId, int $capturedRevision, string $category, bool $terminal, ?string $socialAccountId = null): void
    {
        DB::transaction(function () use ($capturedRevision, $category, $socialAccountId, $stateId, $terminal): void {
            $state = AnalyticsSyncState::query()->lockForUpdate()->find($stateId);

            if (! $state
                || ($socialAccountId !== null && $state->social_account_id !== $socialAccountId)
                || (int) data_get($state->checkpoint, 'revision', 0) !== $capturedRevision) {
                return;
            }

            $state->update([
                'status' => $terminal ? SyncStatus::Failed : SyncStatus::Running,
                'last_error_category' => mb_substr($category, 0, 64),
            ]);
        });
    }

    public function resetInvalidCursor(string $stateId, int $capturedRevision, ?string $socialAccountId = null): bool
    {
        return DB::transaction(function () use ($capturedRevision, $socialAccountId, $stateId): bool {
            $state = AnalyticsSyncState::query()->lockForUpdate()->find($stateId);

            if (! $state
                || ($socialAccountId !== null && $state->social_account_id !== $socialAccountId)
                || (int) data_get($state->checkpoint, 'revision', 0) !== $capturedRevision) {
                return false;
            }

            $resets = (int) data_get($state->checkpoint, 'invalid_cursor_resets', 0);

            if ($resets >= 1) {
                $state->update([
                    'status' => $state->collector === SyncCollector::PublicationBackfill
                        ? SyncStatus::Partial
                        : SyncStatus::Failed,
                    'last_error_category' => 'invalid_cursor_repeated',
                ]);

                return false;
            }

            $state->update([
                'status' => SyncStatus::Pending,
                'checkpoint' => [
                    'cursor' => null,
                    'revision' => $capturedRevision,
                    ...(array_key_exists('seen_count', $state->checkpoint ?? []) ? ['seen_count' => 0] : []),
                    ...(! empty(data_get($state->checkpoint, 'had_provider_limit')) ? ['had_provider_limit' => true] : []),
                    'invalid_cursor_resets' => $resets + 1,
                ],
                'last_error_category' => 'invalid_cursor',
            ]);

            return true;
        });
    }

    public function stopExpiredReconnectionCursor(string $stateId, int $capturedRevision, SocialAccount $account): ?string
    {
        return DB::transaction(function () use ($account, $capturedRevision, $stateId): ?string {
            $state = AnalyticsSyncState::query()->lockForUpdate()->find($stateId);

            if (! $state
                || $state->social_account_id !== $account->id
                || (int) data_get($state->checkpoint, 'revision', 0) !== $capturedRevision
                || ! data_get($state->checkpoint, 'resumed_after_disconnect')
                || $state->oldest_reached_at === null) {
                return null;
            }

            $state->update([
                'status' => SyncStatus::Partial,
                'checkpoint' => [
                    'cursor' => null,
                    'revision' => $capturedRevision,
                    ...(array_key_exists('seen_count', $state->checkpoint ?? [])
                        ? ['seen_count' => (int) data_get($state->checkpoint, 'seen_count')]
                        : []),
                ],
                'last_error_category' => 'reconnect_cursor_expired',
            ]);

            return $this->initializeDiscovery($account, $state->high_watermark_at)->id;
        });
    }

    private function initializeDiscovery(SocialAccount $account, ?CarbonImmutable $highWatermark): AnalyticsSyncState
    {
        $latest = AnalyticsPublication::query()
            ->where('social_account_id', $account->id)
            ->max('provider_published_at');
        $initialHighWatermark = $highWatermark
            ?? ($latest ? CarbonImmutable::parse($latest, 'UTC') : CarbonImmutable::now('UTC'));

        $state = AnalyticsSyncState::query()
            ->where('social_account_id', $account->id)
            ->forCollector(SyncCollector::PublicationDiscovery)
            ->first()
            ?? AnalyticsSyncState::query()->firstOrCreate([
                ...AnalyticsSyncState::identityFor($account),
                'collector' => SyncCollector::PublicationDiscovery,
            ], [
                'social_account_id' => $account->id,
                'status' => SyncStatus::Pending,
                'checkpoint' => ['cursor' => null, 'revision' => 0],
            ]);

        if ($state->workspace_id === null) {
            $state->update(AnalyticsSyncState::identityFor($account));
        }

        if (! $state->high_watermark_at || $initialHighWatermark->greaterThan($state->high_watermark_at)) {
            $state->update(['high_watermark_at' => $initialHighWatermark]);
        }

        return $state;
    }

    private function earlier(?CarbonImmutable $current, mixed $candidate): ?CarbonImmutable
    {
        if (! $candidate) {
            return $current;
        }

        $candidate = CarbonImmutable::parse($candidate, 'UTC');

        return ! $current || $candidate->lessThan($current) ? $candidate : $current;
    }

    private function later(?CarbonImmutable $current, mixed $candidate): ?CarbonImmutable
    {
        if (! $candidate) {
            return $current;
        }

        $candidate = CarbonImmutable::parse($candidate, 'UTC');

        return ! $current || $candidate->greaterThan($current) ? $candidate : $current;
    }
}

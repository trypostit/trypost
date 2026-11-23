<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\PublicationPage;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\MastodonPublicationCollector;
use App\Support\ChannelPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AdvanceAnalyticsSyncState
{
    /** X serves only the 800 most recent posts of a timeline read with exclude=replies. */
    private const X_TIMELINE_LIMIT = 800;

    public function __construct(
        private readonly UpsertAnalyticsPublication $publications,
        private readonly ResolveAnalyticsAccountKey $accountKeys,
    ) {}

    /**
     * Before permission pauses existed, a history request missing its scope
     * finished as provider_limited. Preserve a known provider limit on losing
     * the grant, and reopen a permission-limited backfill on regaining it.
     *
     * @param  list<string>  $previousScopes
     */
    public function historyGrantChanged(SocialAccount $account, array $previousScopes): void
    {
        if (blank($previousScopes) || blank($account->scopes)) {
            return;
        }

        if (blank(ChannelPermissions::missingFrom($previousScopes, $account->platform, GatedFeature::PostHistory))) {
            if (ChannelPermissions::lacks($account, GatedFeature::PostHistory)) {
                $this->rememberLegacyHistoryLimit($account, missingHistoryGrant: false);
            }

            return;
        }

        if (! ChannelPermissions::permits($account, GatedFeature::PostHistory)) {
            return;
        }

        $restart = ['status' => SyncStatus::Pending, 'last_error_category' => null];
        if ($account->platform === Platform::X) {
            $restart['checkpoint->seen_count'] = 0;
        }

        $account->analyticsSyncStates()
            ->legacyLimitedBackfill()
            ->update($restart);
    }

    /** Keep the evidence for a legacy history limit before disconnect removes the account and its scopes. */
    public function rememberDisconnectedHistoryGrant(SocialAccount $account): void
    {
        if (blank($account->scopes) || blank(ChannelPermissions::scopes($account->platform, GatedFeature::PostHistory))) {
            return;
        }

        $this->rememberLegacyHistoryLimit($account, ChannelPermissions::lacks($account, GatedFeature::PostHistory));
    }

    private function rememberLegacyHistoryLimit(SocialAccount $account, bool $missingHistoryGrant): void
    {
        DB::transaction(function () use ($account, $missingHistoryGrant): void {
            $state = $account->analyticsSyncStates()
                ->legacyLimitedBackfill()
                ->whereNull('checkpoint->missing_history_grant')
                ->lockForUpdate()
                ->first();
            if ($state !== null) {
                $state->update(['checkpoint' => [
                    ...($state->checkpoint ?? []),
                    'missing_history_grant' => $missingHistoryGrant,
                ]]);
            }
        });
    }

    /** Preserve the cursor and invalidate any in-flight page's checkpoint revision. */
    public function pause(string $stateId, string $socialAccountId): void
    {
        DB::transaction(function () use ($stateId, $socialAccountId): void {
            $state = AnalyticsSyncState::query()
                ->where('social_account_id', $socialAccountId)
                ->pendingOrRunning()
                ->lockForUpdate()
                ->find($stateId);

            if (blank($state)) {
                return;
            }

            $checkpoint = $state->checkpoint ?? [];
            $state->update([
                'status' => SyncStatus::Paused,
                'checkpoint' => [
                    ...$checkpoint,
                    'revision' => (int) data_get($checkpoint, 'revision', 0) + 1,
                ],
                'last_error_category' => null,
            ]);
        });
    }

    /**
     * @return array{cursor: ?string, revision: int, cutoff: CarbonImmutable}|null
     */
    public function begin(string $stateId, bool $restartTerminal = false, ?string $socialAccountId = null): ?array
    {
        return DB::transaction(function () use ($stateId, $restartTerminal, $socialAccountId): ?array {
            $state = AnalyticsSyncState::query()->lockForUpdate()->find($stateId);

            if ($state === null
                || ($socialAccountId !== null && $state->social_account_id !== $socialAccountId)
                || ($state->isTerminal() && ! $restartTerminal)) {
                return null;
            }

            $checkpoint = $this->checkpointForRun($state, $restartTerminal);

            $state->update([
                'status' => SyncStatus::Running,
                'checkpoint' => $checkpoint,
                'last_error_category' => null,
            ]);

            $cursor = $checkpoint['cursor'];

            return [
                'cursor' => is_string($cursor) && $cursor !== '' ? $cursor : null,
                'revision' => $checkpoint['revision'],
                'cutoff' => $this->cutoffForRun($state),
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

            if ((int) data_get($checkpoint, 'revision', 0) !== $capturedRevision) {
                return ['advanced' => false, 'terminal' => $state->isTerminal()];
            }

            $publishedAt = collect($page->publications)->pluck('publishedAt');
            $pageOldest = $publishedAt->min();
            $pageNewest = $publishedAt->max();
            $oldest = $this->earlier($state->oldest_reached_at, $pageOldest);
            $isDiscovery = $state->collector === SyncCollector::PublicationDiscovery;
            $runHighWatermark = $this->later(
                $isDiscovery ? $this->later(null, data_get($checkpoint, 'run_high_watermark_at')) : $state->high_watermark_at,
                $pageNewest,
            );
            $reachedTarget = $state->collector === SyncCollector::PublicationBackfill
                && $page->canStopAtTarget
                && $pageOldest
                && $state->target_since
                && $pageOldest->lessThanOrEqualTo($state->target_since);
            $isXBackfill = $state->collector === SyncCollector::PublicationBackfill
                && $account->platform === Platform::X;
            $seenCount = (int) data_get($checkpoint, 'seen_count', 0) + ($page->providerRowCount ?? count($page->publications));
            $xTimelineLimited = $isXBackfill
                && $page->providerExhausted
                && ! $reachedTarget
                && $seenCount >= self::X_TIMELINE_LIMIT;
            $hadProviderLimit = $page->providerLimited || data_get($checkpoint, 'had_provider_limit');
            $finished = $page->providerExhausted || $reachedTarget;

            $partialReason = $page->partialReason;
            if (data_get($checkpoint, 'public_history_only')) {
                $partialReason = MastodonPublicationCollector::PRIVATE_HISTORY_PARTIAL;
            }

            if ($account->platform === Platform::Mastodon) {
                $checkpoint['public_history_only'] = $partialReason === MastodonPublicationCollector::PRIVATE_HISTORY_PARTIAL;
            }

            $privateWatermark = data_get($checkpoint, 'private_history_watermark_at');
            if ($isDiscovery && $partialReason === MastodonPublicationCollector::PRIVATE_HISTORY_PARTIAL) {
                $privateWatermark ??= ($state->high_watermark_at ?? CarbonImmutable::now('UTC'))->toIso8601String();
            } elseif ($finished) {
                $privateWatermark = null;
            }

            $status = match (true) {
                $xTimelineLimited, $hadProviderLimit && $finished => SyncStatus::ProviderLimited,
                filled($partialReason) && $finished => SyncStatus::Partial,
                $finished => SyncStatus::Complete,
                default => SyncStatus::Running,
            };

            $highWatermark = $isDiscovery && $status === SyncStatus::Running
                ? $state->high_watermark_at
                : $this->later($state->high_watermark_at, $runHighWatermark);

            $state->update([
                'status' => $status,
                'checkpoint' => [
                    'cursor' => $status === SyncStatus::Running ? $page->nextCursor : null,
                    'revision' => $capturedRevision,
                    ...Arr::only($checkpoint, ['public_history_only']),
                    ...($privateWatermark !== null ? ['private_history_watermark_at' => $privateWatermark] : []),
                    ...($isXBackfill ? ['seen_count' => $seenCount] : []),
                    ...($hadProviderLimit && (! $isDiscovery || ! $finished) ? ['had_provider_limit' => true] : []),
                    ...($status === SyncStatus::Running && data_get($checkpoint, 'invalid_cursor_resets')
                        ? ['invalid_cursor_resets' => (int) data_get($checkpoint, 'invalid_cursor_resets')]
                        : []),
                    ...($isDiscovery && $status === SyncStatus::Running && $runHighWatermark
                        ? ['run_high_watermark_at' => $runHighWatermark->toIso8601String()]
                        : []),
                ],
                'oldest_reached_at' => $oldest,
                'high_watermark_at' => $highWatermark,
                'last_success_at' => CarbonImmutable::now('UTC'),
                'last_error_category' => match (true) {
                    $hadProviderLimit => 'provider_limited',
                    $xTimelineLimited => 'x_timeline_limit',
                    default => $partialReason,
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
                    ...Arr::only($state->checkpoint ?? [], ['private_history_watermark_at', 'public_history_only']),
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

    /**
     * @return array{
     *     cursor: mixed,
     *     revision: int,
     *     public_history_only?: bool,
     *     private_history_watermark_at?: string,
     *     seen_count?: int,
     *     resumed_after_disconnect?: true,
     *     had_provider_limit?: true,
     *     invalid_cursor_resets?: int,
     *     run_high_watermark_at?: mixed
     * }
     */
    private function checkpointForRun(AnalyticsSyncState $state, bool $restartTerminal): array
    {
        $previous = $state->checkpoint ?? [];
        $restarting = $restartTerminal && $state->isTerminal();
        $cursor = $restarting ? null : data_get($previous, 'cursor');
        $checkpoint = [
            'cursor' => $cursor,
            'revision' => (int) data_get($previous, 'revision', 0) + 1,
        ];

        $network = $state->network ?? $state->socialAccount?->platform->network();
        if ($network === Platform::Mastodon->network()) {
            $wasPublic = $this->mayHaveReadPublicHistory($state);
            $publicOnly = ! $state->socialAccount?->canReadMastodonStatuses();
            $checkpoint['public_history_only'] = $publicOnly;
            if ($wasPublic && ! $publicOnly) {
                $checkpoint['cursor'] = null;
            }

            $privateWatermark = $this->privateDiscoveryWatermark($state, $wasPublic);
            if ($privateWatermark !== null) {
                $checkpoint['private_history_watermark_at'] = $privateWatermark;
            }
        }

        if ($state->collector === SyncCollector::PublicationBackfill && $network === Platform::X->network()) {
            $checkpoint['seen_count'] = $restarting ? 0 : (int) data_get($previous, 'seen_count', 0);
        }

        if (data_get($previous, 'resumed_after_disconnect')) {
            $checkpoint['resumed_after_disconnect'] = true;
        }

        if (data_get($previous, 'had_provider_limit')) {
            $checkpoint['had_provider_limit'] = true;
        }

        $cursorResets = data_get($previous, 'invalid_cursor_resets');
        if (! $restartTerminal && $cursorResets) {
            $checkpoint['invalid_cursor_resets'] = (int) $cursorResets;
        }

        $runHighWatermark = data_get($previous, 'run_high_watermark_at');
        if (! $restarting && $runHighWatermark) {
            $checkpoint['run_high_watermark_at'] = $runHighWatermark;
        }

        return $checkpoint;
    }

    /**
     * Older Mastodon checkpoints lost their visibility when the next page failed.
     * An interrupted run with no marker may have skipped private posts, even if
     * the public posts it saw have since been deleted or read with a new grant.
     */
    private function mayHaveReadPublicHistory(AnalyticsSyncState $state): bool
    {
        $publicOnly = data_get($state->checkpoint, 'public_history_only');
        if ($publicOnly !== null) {
            return $publicOnly;
        }

        if ($state->last_error_category === MastodonPublicationCollector::PRIVATE_HISTORY_PARTIAL) {
            return true;
        }

        if ($state->collector === SyncCollector::PublicationBackfill) {
            return filled(data_get($state->checkpoint, 'cursor'));
        }

        return $state->status !== SyncStatus::Complete && (int) data_get($state->checkpoint, 'revision', 0) > 0;
    }

    /** Replay from the last complete backfill when a public discovery run saved no private watermark. */
    private function privateDiscoveryWatermark(AnalyticsSyncState $state, bool $wasPublic): ?string
    {
        if ($state->collector !== SyncCollector::PublicationDiscovery) {
            return null;
        }

        $saved = data_get($state->checkpoint, 'private_history_watermark_at');
        if ($saved !== null) {
            return $saved;
        }

        if (! $wasPublic) {
            return null;
        }

        $backfill = AnalyticsSyncState::query()
            ->where('social_account_id', $state->social_account_id)
            ->forCollector(SyncCollector::PublicationBackfill)
            ->first();

        if ($backfill?->status === SyncStatus::Complete && $backfill->high_watermark_at !== null) {
            return $backfill->high_watermark_at->toIso8601String();
        }

        return ($backfill?->target_since ?? CarbonImmutable::now('UTC')->subDays(365))->toIso8601String();
    }

    private function cutoffForRun(AnalyticsSyncState $state): CarbonImmutable
    {
        if ($state->collector === SyncCollector::PublicationBackfill) {
            return $state->target_since ?? CarbonImmutable::now('UTC')->subDays(365);
        }

        $overlapHours = max(0, (int) config('trypost.analytics.discovery_overlap_hours'));

        $watermark = $state->high_watermark_at ?? CarbonImmutable::now('UTC');
        $privateWatermark = data_get($state->checkpoint, 'private_history_watermark_at');
        if ($privateWatermark !== null && ! data_get($state->checkpoint, 'public_history_only')) {
            $watermark = CarbonImmutable::parse($privateWatermark, 'UTC');
        }

        return $watermark->subHours($overlapHours);
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

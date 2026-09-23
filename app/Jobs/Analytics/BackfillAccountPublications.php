<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Actions\Analytics\QueuePublicationMetricsForPage;
use App\Enums\Analytics\SyncStatus;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Support\Analytics\AnalyticsJobLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class BackfillAccountPublications implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 180;

    public function __construct(
        public string $socialAccountId,
        public string $syncStateId,
    ) {
        $this->onQueue('analytics');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new RateLimited('analytics-publications'),
            (new WithoutOverlapping("analytics-publication-backfill:{$this->socialAccountId}"))
                ->releaseAfter(300)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function providerRateLimitKey(): string
    {
        $account = SocialAccount::query()->find($this->socialAccountId);

        return $account ? $account->platform->network() : 'missing';
    }

    public function backoff(): array
    {
        return [300, 3600, 7200, 10800, 14400];
    }

    public function handle(
        AdvanceAnalyticsSyncState $sync,
        PublicationHistoryCollectorFactory $collectors,
        QueuePublicationMetricsForPage $metrics,
    ): void {
        $account = SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->find($this->socialAccountId);

        if (! $account) {
            return;
        }

        $capture = $sync->begin($this->syncStateId, socialAccountId: $account->id);

        if (! $capture) {
            return;
        }

        $cursorLabel = $capture['cursor'] === null ? 'cursor:start' : 'cursor:'.hash('sha256', $capture['cursor']);
        app(AnalyticsJobLog::class)->record($account, 'publication_backfill', $cursorLabel, $this->attempts(), 'started');

        try {
            $page = $collectors->for($account)->page($account, $capture['cursor'], $capture['cutoff']);
            $result = $sync->handle($this->syncStateId, $capture['revision'], $account, $page);
        } catch (AnalyticsCollectionException $exception) {
            app(AnalyticsJobLog::class)->record($account, 'publication_backfill', $cursorLabel, $this->attempts(), $exception->category);
            if ($exception->category === 'invalid_cursor') {
                $discoveryStateId = $sync->stopExpiredReconnectionCursor($this->syncStateId, $capture['revision'], $account);

                if ($discoveryStateId) {
                    DiscoverAccountPublications::dispatch($account->id, $discoveryStateId)->afterCommit();

                    return;
                }

                if ($sync->resetInvalidCursor($this->syncStateId, $capture['revision'], $account->id)) {
                    self::dispatch($account->id, $this->syncStateId)->afterCommit();
                }

                return;
            }

            $transient = in_array($exception->category, ['transient', 'rate_limited'], true);
            $sync->recordFailure($this->syncStateId, $capture['revision'], $exception->category, ! $transient, $account->id);

            if ($transient) {
                throw $exception;
            }

            return;
        }

        app(AnalyticsJobLog::class)->record($account, 'publication_backfill', $cursorLabel, $this->attempts(), $result['terminal'] ? 'completed' : 'page_advanced');

        $metrics->handle($account, $page);

        if ($result['advanced'] && ! $result['terminal']) {
            self::dispatch($account->id, $this->syncStateId)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $state = AnalyticsSyncState::query()->find($this->syncStateId);

        $account = SocialAccount::query()->find($this->socialAccountId);

        if ($account) {
            app(AnalyticsJobLog::class)->record($account, 'publication_backfill', 'cursor:queue_failed', $this->attempts(), 'queue_failed');
        }

        if ($state && $state->social_account_id === $this->socialAccountId && ! $state->isTerminal()) {
            $state->update([
                'status' => SyncStatus::Failed,
                'last_error_category' => 'queue_failed',
            ]);
        }
    }
}

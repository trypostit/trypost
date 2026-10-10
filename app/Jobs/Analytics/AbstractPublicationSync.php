<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Actions\Analytics\QueuePublicationMetricsForPage;
use App\Actions\Post\ImportExternalPosts;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Support\Analytics\AnalyticsJobLog;
use App\Support\Analytics\AnalyticsRateLimits;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

abstract class AbstractPublicationSync implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 6;

    public int $timeout = 180;

    private const int MAX_TRANSIENT_RETRIES = 5;

    public int $transientRetries = 0;

    public function __construct(
        public string $socialAccountId,
        public string $syncStateId,
        int $transientRetries = 0,
    ) {
        $this->transientRetries = $transientRetries;
        $this->onQueue('analytics');
    }

    abstract protected function collector(): SyncCollector;

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new RateLimited('analytics-publications'),
            (new WithoutOverlapping("analytics-{$this->collector()->value}:{$this->socialAccountId}"))
                ->releaseAfter(300)
                ->expireAfter($this->timeout + 30),
        ];
    }

    /** @return list<Limit> */
    public function analyticsRateLimits(): array
    {
        return AnalyticsRateLimits::for(SocialAccount::query()->find($this->socialAccountId));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 3600, 7200, 10800, 14400];
    }

    public function handle(
        AdvanceAnalyticsSyncState $sync,
        PublicationHistoryCollectorFactory $collectors,
        QueuePublicationMetricsForPage $metrics,
        AnalyticsJobLog $log,
    ): void {
        $channel = SocialAccount::query()
            ->connected()
            ->includedInAnalytics()
            ->find($this->socialAccountId);

        if (! $channel?->workspace->account->hasAppAccess() || ! $this->mayStart($channel)) {
            return;
        }

        $capture = $sync->begin(
            $this->syncStateId,
            restartTerminal: $this->restartTerminal(),
            socialAccountId: $channel->id,
        );

        if (! $capture) {
            return;
        }

        $collector = $this->collector()->value;
        $cursorLabel = $capture['cursor'] === null ? 'cursor:start' : 'cursor:'.hash('sha256', $capture['cursor']);
        $log->record($channel, $collector, $cursorLabel, $this->attempts(), 'started');

        try {
            $page = $collectors->for($channel)->page($channel, $capture['cursor'], $capture['cutoff']);
            $result = $sync->handle($this->syncStateId, $capture['revision'], $channel, $page);
        } catch (AnalyticsCollectionException $exception) {
            if ($exception->category === 'invalid_cursor') {
                $log->record($channel, $collector, $cursorLabel, $this->attempts(), $exception->category);
                $this->handleInvalidCursor($sync, $channel, $capture['revision']);

                return;
            }

            $this->recordFailure($sync, $log, $channel, $capture['revision'], $cursorLabel, $exception->category, $exception->retryAt);

            return;
        } catch (ConnectionException) {
            $this->recordFailure($sync, $log, $channel, $capture['revision'], $cursorLabel, 'transient', null);

            return;
        }

        $log->record($channel, $collector, $cursorLabel, $this->attempts(), $result['terminal'] ? 'completed' : 'page_advanced');
        foreach (rescue(fn (): array => ImportExternalPosts::execute($channel), []) as $postId) {
            ImportExternalPostMedia::dispatch($postId);
        }
        $metrics->handle($channel, $page);

        if ($result['advanced'] && ! $result['terminal']) {
            static::dispatch($channel->id, $this->syncStateId)->afterCommit();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $state = AnalyticsSyncState::query()->find($this->syncStateId);
        $account = SocialAccount::query()->find($this->socialAccountId);

        if ($account) {
            app(AnalyticsJobLog::class)->record($account, $this->collector()->value, 'cursor:queue_failed', $this->attempts(), 'queue_failed');
        }

        if ($state && $state->social_account_id === $this->socialAccountId && ! $state->isTerminal()) {
            $state->update([
                'status' => SyncStatus::Failed,
                'last_error_category' => 'queue_failed',
            ]);
        }
    }

    private function recordFailure(
        AdvanceAnalyticsSyncState $sync,
        AnalyticsJobLog $log,
        SocialAccount $account,
        int $revision,
        string $cursorLabel,
        string $category,
        ?CarbonImmutable $providerRetryAt,
    ): void {
        $collector = $this->collector()->value;
        $transient = in_array($category, ['transient', 'rate_limited'], true);

        if (! $transient || $this->transientRetries >= self::MAX_TRANSIENT_RETRIES) {
            $sync->recordFailure($this->syncStateId, $revision, $transient ? 'queue_failed' : $category, true, $account->id);
            $log->record($account, $collector, $cursorLabel, $this->attempts(), $category);

            return;
        }

        $sync->recordFailure($this->syncStateId, $revision, $category, false, $account->id);

        $delay = $this->transientDelaySeconds($providerRetryAt);
        $log->record($account, $collector, $cursorLabel, $this->attempts(), $category, CarbonImmutable::now('UTC')->addSeconds($delay)->toIso8601String());
        static::dispatch($account->id, $this->syncStateId, $this->transientRetries + 1)->delay($delay);
    }

    private function transientDelaySeconds(?CarbonImmutable $providerRetryAt): int
    {
        $backoff = $this->backoff();
        $delay = $backoff[min($this->transientRetries, count($backoff) - 1)];
        $now = CarbonImmutable::now('UTC');
        $providerDelay = $providerRetryAt ? (int) ceil($now->diffInSeconds($providerRetryAt->min($now->endOfDay()), false)) : 0;

        return max($delay, $providerDelay);
    }

    protected function mayStart(SocialAccount $account): bool
    {
        return true;
    }

    protected function restartTerminal(): bool
    {
        return false;
    }

    protected function resetInvalidCursor(AdvanceAnalyticsSyncState $sync, SocialAccount $account, int $revision): void
    {
        if ($sync->resetInvalidCursor($this->syncStateId, $revision, $account->id)) {
            static::dispatch($account->id, $this->syncStateId)->afterCommit();
        }
    }

    abstract protected function handleInvalidCursor(AdvanceAnalyticsSyncState $sync, SocialAccount $account, int $revision): void;
}

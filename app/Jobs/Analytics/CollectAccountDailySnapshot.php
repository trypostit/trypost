<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Analytics\WriteAccountDailySnapshot;
use App\Enums\Analytics\ObservationProvenance;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Support\Analytics\AnalyticsJobLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class CollectAccountDailySnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 150;

    public function __construct(
        public string $socialAccountId,
        public string $observationDate,
    ) {
        $this->onQueue('analytics');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("analytics-followers:{$this->socialAccountId}:{$this->observationDate}"))
                ->releaseAfter(300)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->observationDate, 'UTC')->endOfDay();
    }

    public function handle(
        FollowerCollectorFactory $collectors,
        ResolveAnalyticsAccountKey $accountKeys,
        WriteAccountDailySnapshot $writer,
    ): void {
        $account = SocialAccount::query()
            ->connected()
            ->active()
            ->find($this->socialAccountId);

        if (! $account
            || ! $collectors->supports($account->platform)) {
            return;
        }

        $alreadyCollected = AnalyticsAccountDailySnapshot::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('social_account_key', $accountKeys->for($account))
            ->whereDate('snapshot_date', $this->observationDate)
            ->where('provenance', ObservationProvenance::Actual)
            ->exists();

        if ($alreadyCollected) {
            return;
        }

        try {
            $observation = $collectors->for($account->platform)->collect(
                $account,
                CarbonImmutable::parse($this->observationDate, 'UTC'),
            );
            $writer->handle($account, $observation);
            app(AnalyticsJobLog::class)->record($account, 'followers', $this->observationDate, $this->attempts(), 'actual');
        } catch (AnalyticsCollectionException $exception) {
            if (in_array($exception->category, ['authentication', 'permission'], true)) {
                app(AnalyticsJobLog::class)->record($account, 'followers', $this->observationDate, $this->attempts(), $exception->category);

                return;
            }

            if (! in_array($exception->category, ['transient', 'rate_limited'], true) || $this->attempts() >= 6) {
                app(AnalyticsJobLog::class)->record($account, 'followers', $this->observationDate, $this->attempts(), $exception->category);

                return;
            }

            $nextAttempt = $this->nextAttemptAt($exception->retryAt);

            if ($nextAttempt) {
                app(AnalyticsJobLog::class)->record($account, 'followers', $this->observationDate, $this->attempts(), $exception->category, $nextAttempt->toIso8601String());
                $this->release($nextAttempt);
            } else {
                app(AnalyticsJobLog::class)->record($account, 'followers', $this->observationDate, $this->attempts(), 'retry_window_exhausted');
            }
        }
    }

    private function nextAttemptAt(?CarbonImmutable $providerRetryAt): ?CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $endOfDay = CarbonImmutable::parse($this->observationDate, 'UTC')->endOfDay();
        $nextWindow = null;

        foreach ([6, 10, 14, 18, 22] as $hour) {
            $window = CarbonImmutable::parse($this->observationDate, 'UTC')->setTime($hour, 0);

            if ($window->greaterThanOrEqualTo($now)) {
                $nextWindow = $window;

                break;
            }
        }

        if (! $nextWindow) {
            return null;
        }

        $nextAttempt = $providerRetryAt && $providerRetryAt->greaterThan($nextWindow)
            ? $providerRetryAt
            : $nextWindow;

        return $nextAttempt->lessThanOrEqualTo($endOfDay) ? $nextAttempt : null;
    }
}

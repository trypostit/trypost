<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Analytics\WriteAccountDailySnapshot;
use App\Dto\Analytics\AccountDailyObservation;
use App\Enums\Analytics\ObservationProvenance;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Support\Analytics\AnalyticsJobLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeAccountDailySnapshots implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900];
    }

    public function __construct(public ?string $observationDate = null, public int $daysAgo = 0)
    {
        $this->onQueue('analytics');
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Analytics follower snapshot finalization failed', [
            'observation_date' => $this->observationDate,
            'days_ago' => $this->daysAgo,
            'exception' => $exception,
        ]);
    }

    public function handle(
        WriteAccountDailySnapshot $writer,
        ?FollowerCollectorFactory $collectors = null,
    ): void {
        $collectors ??= app(FollowerCollectorFactory::class);
        $date = CarbonImmutable::parse(
            $this->observationDate ?? CarbonImmutable::now('UTC')->subDays($this->daysAgo)->toDateString(),
            'UTC',
        );

        SocialAccount::query()
            ->connected()
            ->active()
            ->reorder()
            ->lazyById(200)
            ->each(function (SocialAccount $account) use ($collectors, $date, $writer): void {
                if (! $collectors->supports($account->platform)) {
                    return;
                }

                $hasActual = AnalyticsAccountDailySnapshot::query()
                    ->where('workspace_id', $account->workspace_id)
                    ->whereDate('snapshot_date', $date->toDateString())
                    ->where('social_account_key', app(ResolveAnalyticsAccountKey::class)->for($account))
                    ->where('provenance', ObservationProvenance::Actual)
                    ->exists();

                if ($hasActual) {
                    return;
                }

                $previous = AnalyticsAccountDailySnapshot::query()
                    ->where('workspace_id', $account->workspace_id)
                    ->where('network', $account->platform->network())
                    ->where('platform_user_id', $account->platform_user_id)
                    ->whereDate('snapshot_date', '<', $date->toDateString())
                    ->whereNotNull('followers_count')
                    ->latest('snapshot_date')
                    ->first();

                if (! $previous) {
                    app(AnalyticsJobLog::class)->record($account, 'followers', $date->toDateString(), $this->attempts(), 'unavailable_no_history');

                    return;
                }

                $writer->handle($account, new AccountDailyObservation(
                    date: $date,
                    followers: $previous->followers_count,
                    provenance: ObservationProvenance::CarriedForward,
                    precision: $previous->precision,
                    providerObservedAt: $previous->provider_observed_at,
                    collectedAt: CarbonImmutable::now('UTC'),
                    metrics: $previous->metrics ?? [],
                ));
                app(AnalyticsJobLog::class)->record($account, 'followers', $date->toDateString(), $this->attempts(), 'carried_forward');
            });
    }
}

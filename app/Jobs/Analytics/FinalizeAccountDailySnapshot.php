<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Analytics\WriteAccountDailySnapshot;
use App\Dto\Analytics\AccountDailyObservation;
use App\Enums\Analytics\ObservationProvenance;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Support\Analytics\AnalyticsJobLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeAccountDailySnapshot implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900];
    }

    public function __construct(public string $socialAccountId, public string $observationDate)
    {
        $this->onQueue('analytics');
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Analytics follower snapshot finalization failed', [
            'social_account_id' => $this->socialAccountId,
            'observation_date' => $this->observationDate,
            'exception' => $exception,
        ]);
    }

    public function handle(
        ResolveAnalyticsAccountKey $accountKeys,
        WriteAccountDailySnapshot $writer,
        AnalyticsJobLog $log,
    ): void {
        $account = SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->find($this->socialAccountId);

        if (! $account) {
            return;
        }

        $date = CarbonImmutable::parse($this->observationDate, 'UTC');
        $hasActual = AnalyticsAccountDailySnapshot::query()
            ->where('workspace_id', $account->workspace_id)
            ->whereDate('date', $this->observationDate)
            ->where('social_account_key', $accountKeys->for($account))
            ->where('provenance', ObservationProvenance::Actual)
            ->exists();

        if ($hasActual) {
            return;
        }

        $previous = AnalyticsAccountDailySnapshot::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('network', $account->platform->network())
            ->where('platform_user_id', $account->platform_user_id)
            ->whereDate('date', '<', $this->observationDate)
            ->whereNotNull('followers_count')
            ->latest('date')
            ->first();

        if (! $previous) {
            $log->record($account, 'followers', $this->observationDate, $this->attempts(), 'unavailable_no_history');

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
        $log->record($account, 'followers', $this->observationDate, $this->attempts(), 'carried_forward');
    }
}

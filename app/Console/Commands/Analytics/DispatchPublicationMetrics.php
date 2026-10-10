<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\SyncCadence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('analytics:dispatch-publication-metrics')]
#[Description('Dispatch daily publication metric collection for eligible accounts')]
class DispatchPublicationMetrics extends Command
{
    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');

        SocialAccount::query()
            ->connected()
            ->includedInAnalytics()
            ->with('workspace.account.subscriptions')
            ->lazyById(100)
            ->each(function (SocialAccount $account) use ($now): void {
                if (! $account->workspace->account->hasAppAccess()) {
                    return;
                }

                $days = SyncCadence::metricsWindowDays($account->platform);

                AnalyticsPublication::query()
                    ->available()
                    ->where('social_account_id', $account->id)
                    ->where('provider_published_at', '<=', $now->subMinutes(SyncCadence::FIRST_READ_DELAY_MINUTES))
                    ->where(fn (Builder $query): Builder => $query
                        ->where(fn (Builder $due) => $this->due($due, $account->platform, $now))
                        ->orWhereDoesntHave('dailySnapshots'))
                    ->where(function ($query) use ($now): void {
                        $query->where('content_type', '!=', PublicationContentType::Story)
                            ->orWhere('provider_published_at', '>=', $now->subDay());
                    })
                    ->lazyById(100)
                    ->each(fn (AnalyticsPublication $publication) => CollectPublicationMetrics::dispatch(
                        $publication->id,
                        $now->toDateString(),
                        $publication->provider_published_at->lessThan($now->subDays($days)->startOfDay()),
                    ));
            });

        return self::SUCCESS;
    }

    /**
     * Posts the daily run reads again: every day of the window, or only the scheduled ages on X.
     */
    private function due(Builder $query, Platform $platform, CarbonImmutable $now): void
    {
        $ages = SyncCadence::metricsDays($platform);

        if ($ages === null) {
            $query->where('provider_published_at', '>=', $now->subDays(SyncCadence::metricsWindowDays($platform))->startOfDay());

            return;
        }

        foreach ($ages as $age) {
            $query->orWhereBetween('provider_published_at', [
                $now->subDays($age)->startOfDay(),
                $now->subDays($age)->endOfDay(),
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Enums\Analytics\PublicationContentType;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\SyncCadence;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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
            ->lazyById(100)
            ->each(function (SocialAccount $account) use ($now): void {
                $days = SyncCadence::metricsWindowDays($account->platform);

                AnalyticsPublication::query()
                    ->available()
                    ->where('social_account_id', $account->id)
                    ->where(function ($query) use ($days, $now): void {
                        $query->where('provider_published_at', '>=', $now->subDays($days)->startOfDay())
                            ->orWhereDoesntHave('dailySnapshots');
                    })
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
}

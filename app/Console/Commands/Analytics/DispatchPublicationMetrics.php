<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
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
            ->active()
            ->includedInAnalytics()
            ->reorder()
            ->lazyById(100)
            ->each(function (SocialAccount $account) use ($now): void {
                $days = $account->platform === Platform::X ? 20 : 30;

                AnalyticsPublication::query()
                    ->available()
                    ->where('social_account_id', $account->id)
                    ->where('provider_published_at', '>=', $now->subDays($days)->startOfDay())
                    ->lazyById(100)
                    ->each(fn (AnalyticsPublication $publication) => CollectPublicationMetrics::dispatch(
                        $publication->id,
                        $now->toDateString(),
                    ));
            });

        return self::SUCCESS;
    }
}

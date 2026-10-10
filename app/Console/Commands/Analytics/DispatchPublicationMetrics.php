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
                if (! $account->hasAppAccess()) {
                    return;
                }

                $days = SyncCadence::metricsWindowDays($account->platform);

                AnalyticsPublication::query()
                    ->available()
                    ->where('social_account_id', $account->id)
                    ->where(function (Builder $query) use ($account, $days, $now): void {
                        $ages = SyncCadence::metricsDays($account->platform);

                        $query->where(fn (Builder $window): Builder => $ages === null
                            ? $window->where('provider_published_at', '>=', $now->subDays($days)->startOfDay())
                            : $window->where(function (Builder $scheduled) use ($ages, $now): void {
                                foreach ($ages as $age) {
                                    $scheduled->orWhereBetween('provider_published_at', [
                                        $now->subDays($age)->startOfDay(),
                                        $now->subDays($age)->endOfDay(),
                                    ]);
                                }
                            }))
                            ->orWhere(fn (Builder $unmeasured): Builder => $unmeasured
                                ->whereDoesntHave('dailySnapshots')
                                ->when($account->platform === Platform::X, fn (Builder $settled): Builder => $settled
                                    ->where('provider_published_at', '<=', $now->subMinutes(SyncCadence::X_FIRST_READ_DELAY_MINUTES))));
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

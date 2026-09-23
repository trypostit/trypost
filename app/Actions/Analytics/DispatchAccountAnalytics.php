<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use Carbon\CarbonImmutable;
use Throwable;

class DispatchAccountAnalytics
{
    public function handle(SocialAccount $socialAccount): void
    {
        try {
            $currentAccount = SocialAccount::query()
                ->connected()
                ->active()
                ->includedInAnalytics()
                ->find($socialAccount->id);

            if (! $currentAccount) {
                return;
            }

            if (app(FollowerCollectorFactory::class)->supports($currentAccount->platform)) {
                CollectAccountDailySnapshot::dispatch(
                    $currentAccount->id,
                    CarbonImmutable::now('UTC')->toDateString(),
                )->afterCommit();
            }

            BootstrapAccountAnalytics::dispatch($currentAccount->id, true)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

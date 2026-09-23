<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:dispatch-account-daily')]
#[Description('Dispatch daily follower collection for eligible social accounts')]
class DispatchAccountDailyAnalytics extends Command
{
    public function handle(FollowerCollectorFactory $collectors): int
    {
        $date = CarbonImmutable::now('UTC')->toDateString();

        SocialAccount::query()
            ->connected()
            ->active()
            ->reorder()
            ->lazyById(200)
            ->each(function (SocialAccount $account) use ($collectors, $date): void {
                if ($collectors->supports($account->platform)) {
                    CollectAccountDailySnapshot::dispatch($account->id, $date);
                }
            });

        return self::SUCCESS;
    }
}

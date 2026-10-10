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
    public function __construct(private readonly FollowerCollectorFactory $collectors) {}

    public function handle(SocialAccount $socialAccount): void
    {
        try {
            $connectedChannel = SocialAccount::query()
                ->connected()
                ->includedInAnalytics()
                ->find($socialAccount->id);

            if (! $connectedChannel?->workspace->account->hasAppAccess()) {
                return;
            }

            if ($this->collectors->supports($connectedChannel->platform)) {
                CollectAccountDailySnapshot::dispatch(
                    $connectedChannel->id,
                    CarbonImmutable::now('UTC')->toDateString(),
                )->afterCommit();
            }

            BootstrapAccountAnalytics::dispatch($connectedChannel->id, true)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

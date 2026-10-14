<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;

class MoveAnalyticsIdentity
{
    /**
     * Carries a card's analytics over when a reconnect seats it under another
     * platform of its network (Instagram Login and Instagram through Facebook
     * report different ids for one account). Its publications and daily
     * snapshots follow the card, so their metrics keep being collected. A sync
     * state follows it too, unless the new identity already has one from an
     * earlier connection: the card takes that one over and the old one is
     * released, as a disconnect would leave it.
     */
    public function handle(SocialAccount $account, string $previousPlatformUserId): void
    {
        $identity = AnalyticsSyncState::identityFor($account);

        $account->analyticsSyncStates()->get()->each(function (AnalyticsSyncState $state) use ($account, $identity): void {
            $heldByIdentity = AnalyticsSyncState::query()
                ->forIdentity($account)
                ->forCollector($state->collector)
                ->whereKeyNot($state->id)
                ->exists();

            $state->update($heldByIdentity ? ['social_account_id' => null] : $identity);
        });

        foreach ([AnalyticsPublication::query(), AnalyticsAccountDailySnapshot::query()] as $rows) {
            $rows->where('social_account_id', $account->id)
                ->where('platform_user_id', $previousPlatformUserId)
                ->update([
                    'platform' => $account->platform,
                    'platform_user_id' => $account->platform_user_id,
                ]);
        }
    }
}

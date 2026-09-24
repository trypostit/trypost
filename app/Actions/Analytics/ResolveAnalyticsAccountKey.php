<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;

class ResolveAnalyticsAccountKey
{
    public function for(SocialAccount $account): string
    {
        $identity = [
            'workspace_id' => $account->workspace_id,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
        ];

        $snapshotKey = AnalyticsAccountDailySnapshot::query()
            ->where($identity)
            ->latest('date')
            ->value('social_account_key');

        if (is_string($snapshotKey)) {
            return $snapshotKey;
        }

        $publicationKey = AnalyticsPublication::query()
            ->where($identity)
            ->latest('provider_published_at')
            ->value('social_account_key');

        return is_string($publicationKey) ? $publicationKey : $account->id;
    }
}

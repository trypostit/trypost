<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;

class WriteAccountDailySnapshot
{
    public function __construct(private readonly ResolveAnalyticsAccountKey $accountKeys) {}

    public function handle(
        SocialAccount $account,
        AccountDailyObservation $observation,
    ): AnalyticsAccountDailySnapshot {
        return DB::transaction(function () use ($account, $observation): AnalyticsAccountDailySnapshot {
            return AnalyticsAccountDailySnapshot::query()->updateOrCreate(
                [
                    'workspace_id' => $account->workspace_id,
                    'social_account_key' => $this->accountKeys->for($account),
                    'snapshot_date' => $observation->date->toDateString(),
                ],
                [
                    'social_account_id' => $account->id,
                    'network' => $account->platform->network(),
                    'platform_user_id' => $account->platform_user_id,
                    'platform' => $account->platform,
                    'account_display_name' => $account->display_name,
                    'account_username' => $account->username,
                    'account_avatar_url' => $account->avatar_url,
                    'followers_count' => $observation->followers,
                    'metrics' => $observation->metrics ?: null,
                    'provenance' => $observation->provenance,
                    'precision' => $observation->precision,
                    'provider_observed_at' => $observation->providerObservedAt,
                    'collected_at' => $observation->collectedAt ?? now(),
                ],
            );
        });
    }
}

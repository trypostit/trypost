<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Enums\Account\LifecycleStage;
use App\Models\Account;
use Carbon\CarbonInterface;

class ResolveLifecycleStage
{
    public const ACTIVE_WITHIN_DAYS = 7;

    public const AT_RISK_WITHIN_DAYS = 21;

    public static function for(Account $account, ?CarbonInterface $lastPublishedAt, bool $hasSocialAccount): LifecycleStage
    {
        if (! $account->hasAppAccess()) {
            return $account->hasPaidSubscription() ? LifecycleStage::Churned : LifecycleStage::SignedUp;
        }

        if (! $hasSocialAccount) {
            return LifecycleStage::Onboarding;
        }

        if (blank($lastPublishedAt)) {
            return LifecycleStage::Connected;
        }

        if ($lastPublishedAt->greaterThanOrEqualTo(now()->subDays(self::ACTIVE_WITHIN_DAYS))) {
            return LifecycleStage::Active;
        }

        if ($lastPublishedAt->greaterThanOrEqualTo(now()->subDays(self::AT_RISK_WITHIN_DAYS))) {
            return LifecycleStage::AtRisk;
        }

        return LifecycleStage::Dormant;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\CarbonInterface;

class FirstFullCharge
{
    private const GRACE_DAYS = 2;

    public static function isFirst(CarbonInterface $subscriptionStartedAt, CarbonInterface $chargeAt, string $interval): bool
    {
        $previousCharge = $interval === 'year'
            ? $chargeAt->copy()->subYearsNoOverflow(1)
            : $chargeAt->copy()->subMonthsNoOverflow(1);

        return $subscriptionStartedAt->greaterThan($previousCharge->subDays(self::GRACE_DAYS));
    }
}

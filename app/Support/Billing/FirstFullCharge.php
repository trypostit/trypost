<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\Billing\Interval;
use Carbon\CarbonInterface;

final class FirstFullCharge
{
    private const GRACE_DAYS = 2;

    public static function isFirst(CarbonInterface $subscriptionStartedAt, CarbonInterface $chargeAt, Interval $interval): bool
    {
        $previousCharge = $interval === Interval::Yearly
            ? $chargeAt->copy()->subYearsNoOverflow(1)
            : $chargeAt->copy()->subMonthsNoOverflow(1);

        return $subscriptionStartedAt->greaterThan($previousCharge->subDays(self::GRACE_DAYS));
    }
}

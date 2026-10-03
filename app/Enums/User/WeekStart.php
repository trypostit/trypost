<?php

declare(strict_types=1);

namespace App\Enums\User;

use Carbon\CarbonInterface;

enum WeekStart: string
{
    case Sunday = 'sunday';
    case Monday = 'monday';

    public const DEFAULT = self::Monday;

    public function firstDay(): int
    {
        return $this === self::Sunday ? CarbonInterface::SUNDAY : CarbonInterface::MONDAY;
    }

    public function lastDay(): int
    {
        return $this === self::Sunday ? CarbonInterface::SATURDAY : CarbonInterface::SUNDAY;
    }
}

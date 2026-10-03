<?php

declare(strict_types=1);

namespace App\Enums\AccessToken;

use Carbon\CarbonInterface;

enum ExpiryStatus: string
{
    case Active = 'active';
    case ExpiringSoon = 'expiring_soon';
    case Expired = 'expired';

    public const EXPIRING_SOON_DAYS = 7;

    public static function for(?CarbonInterface $expiresAt): self
    {
        return match (true) {
            $expiresAt === null => self::Active,
            $expiresAt->isPast() => self::Expired,
            $expiresAt->lte(now()->addDays(self::EXPIRING_SOON_DAYS)) => self::ExpiringSoon,
            default => self::Active,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Enums\User;

enum TimeFormat: string
{
    case TwelveHour = '12h';
    case TwentyFourHour = '24h';

    /**
     * The clock of `Locale::DEFAULT` (English): what a row gets when nothing else
     * decides it.
     */
    public const DEFAULT = self::TwelveHour;

    /**
     * The clock a language reads by default: English a 12-hour one, every other
     * supported language a 24-hour one. Used only when a signup cannot tell.
     */
    public static function forLocale(?Locale $locale): self
    {
        return $locale === Locale::English ? self::TwelveHour : self::TwentyFourHour;
    }
}

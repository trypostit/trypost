<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Percentages, worked out one way everywhere: a part of a whole, and the
 * change from a past value. Both round to `$precision` decimals.
 */
final class Percent
{
    /** The part's share of the whole, in percent; an empty whole gives 0. */
    public static function of(float $part, float $whole, int $precision = 1): float
    {
        if ($whole == 0) {
            return 0.0;
        }

        return round($part / $whole * 100, $precision);
    }

    /** How far the value moved from the past one, in percent; null when there is nothing to compare with. */
    public static function change(float $current, float $past, int $precision = 1): ?float
    {
        if ($past == 0) {
            return null;
        }

        return round(($current - $past) / $past * 100, $precision);
    }
}

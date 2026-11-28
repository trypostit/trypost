<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

use Carbon\CarbonInterface;

/**
 * A period picked by name: the last days up to today, the month so far, or
 * custom days. Its values are the `range` the pages and the API send.
 */
enum DateRangePreset: string
{
    case SevenDays = '7d';
    case ThirtyDays = '30d';
    case SixtyDays = '60d';
    case NinetyDays = '90d';
    case MonthToDate = 'mtd';
    case Custom = 'custom';

    /** The period a page covers when none is picked. */
    public const self DEFAULT_RANGE = self::ThirtyDays;

    /**
     * The fixed periods the admin charts offer.
     *
     * @return list<self>
     */
    public static function admin(): array
    {
        return [self::SevenDays, self::ThirtyDays, self::SixtyDays, self::NinetyDays];
    }

    /**
     * The calendar days the period covers in the zone, first and last. Only a
     * custom period reads the days it is given.
     *
     * @return array{start: string, end: string}
     */
    public function dates(string $timezone, ?string $start = null, ?string $end = null): array
    {
        $today = now($timezone)->startOfDay();

        return match ($this) {
            self::SevenDays => self::between($today->subDays(6), $today),
            self::ThirtyDays => self::between($today->subDays(29), $today),
            self::SixtyDays => self::between($today->subDays(59), $today),
            self::NinetyDays => self::between($today->subDays(89), $today),
            self::MonthToDate => self::between($today->startOfMonth(), $today),
            self::Custom => ['start' => (string) $start, 'end' => (string) $end],
        };
    }

    /**
     * @return array{start: string, end: string}
     */
    private static function between(CarbonInterface $start, CarbonInterface $end): array
    {
        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }
}

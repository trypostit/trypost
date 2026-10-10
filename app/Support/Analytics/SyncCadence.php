<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\SocialAccount\Platform;

/**
 * How often Insights data is refreshed.
 */
class SyncCadence
{
    public const int METRICS_WINDOW_DAYS = 30;

    /**
     * X bills every read, so after the first read a post is read again only at these ages
     * (days since its UTC publish date). The last one closes the window.
     *
     * @var list<int>
     */
    public const array X_METRICS_DAYS = [2, 3, 7, 14, 28];

    public const int X_FIRST_READ_DELAY_MINUTES = 60;

    public static function metricsWindowDays(Platform $platform): int
    {
        return $platform === Platform::X ? max(self::X_METRICS_DAYS) : self::METRICS_WINDOW_DAYS;
    }

    /**
     * Ages the daily run reads; null means every day of the window.
     *
     * @return list<int>|null
     */
    public static function metricsDays(Platform $platform): ?array
    {
        return $platform === Platform::X ? self::X_METRICS_DAYS : null;
    }

    /**
     * The page reads the last sync time from the report's coverage rows, so a coverage poll keeps it current.
     *
     * @return array{metrics_days: int}
     */
    public static function toArray(): array
    {
        return [
            'metrics_days' => self::METRICS_WINDOW_DAYS,
        ];
    }
}

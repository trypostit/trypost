<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\SocialAccount\Platform;

/**
 * How often Insights data is refreshed, read from the same settings the scheduler uses.
 */
class SyncCadence
{
    public const int METRICS_WINDOW_DAYS = 30;

    public const int X_METRICS_WINDOW_DAYS = 28;

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
        return $platform === Platform::X ? self::X_METRICS_WINDOW_DAYS : self::METRICS_WINDOW_DAYS;
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
     * @return array{discovery_hours: int, x_discovery_hours: int, metrics_days: int, x_metrics_days: list<int>}
     */
    public static function toArray(): array
    {
        return [
            'discovery_hours' => self::hours((int) config('trypost.analytics.discovery_interval_hours')),
            'x_discovery_hours' => self::hours((int) config('trypost.analytics.x_discovery_interval_hours')),
            'metrics_days' => self::METRICS_WINDOW_DAYS,
            'x_metrics_days' => self::X_METRICS_DAYS,
        ];
    }

    private static function hours(int $hours): int
    {
        return max(1, min(24, $hours));
    }
}

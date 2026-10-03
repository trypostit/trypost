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

    public const int X_METRICS_WINDOW_DAYS = 20;

    public static function metricsWindowDays(Platform $platform): int
    {
        return $platform === Platform::X ? self::X_METRICS_WINDOW_DAYS : self::METRICS_WINDOW_DAYS;
    }

    /**
     * The page reads the last sync time from the report's coverage rows, so a coverage poll keeps it current.
     *
     * @return array{discovery_hours: int, x_discovery_hours: int, metrics_days: int, x_metrics_days: int}
     */
    public static function toArray(): array
    {
        return [
            'discovery_hours' => self::hours((int) config('trypost.analytics.discovery_interval_hours')),
            'x_discovery_hours' => self::hours((int) config('trypost.analytics.x_discovery_interval_hours')),
            'metrics_days' => self::METRICS_WINDOW_DAYS,
            'x_metrics_days' => self::X_METRICS_WINDOW_DAYS,
        ];
    }

    private static function hours(int $hours): int
    {
        return max(1, min(24, $hours));
    }
}

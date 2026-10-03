<?php

declare(strict_types=1);

namespace App\Support\Analytics;

final class ChannelMetrics
{
    /** @var list<string> */
    public const array ORDER = [
        'followers', 'posts', 'reactions', 'comments', 'engagement_rate', 'views',
        'shares', 'saves', 'follows_gained', 'reach', 'watch_time_minutes', 'average_watch_time_seconds',
    ];

    /** @var array<string, string> */
    public const array SORTS = [
        'reactions' => 'metric.reactions_count',
        'comments' => 'metric.comments_count',
        'engagement_rate' => '(metric.engagement_count * 1.0) / NULLIF(metric.exposure_count, 0)',
        'views' => 'metric.views_count',
        'shares' => 'metric.shares_count',
        'saves' => 'metric.saves_count',
        'reach' => 'metric.reach_count',
    ];

    /** @return list<string> */
    public static function sortable(): array
    {
        return array_values(array_filter(self::ORDER, fn (string $metric): bool => array_key_exists($metric, self::SORTS)));
    }

    /** @param  list<string>  $available */
    public static function sortFor(?string $requested, array $available): string
    {
        $sortable = array_values(array_intersect(self::sortable(), $available));

        if ($requested !== null && in_array($requested, $sortable, true)) {
            return $requested;
        }

        return $sortable[0] ?? self::sortable()[0];
    }
}

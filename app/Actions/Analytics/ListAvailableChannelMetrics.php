<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\Analytics\MetricAvailability;
use App\Models\SocialAccount;
use App\Support\Analytics\ChannelMetrics;
use Illuminate\Support\Collection;

class ListAvailableChannelMetrics
{
    private const array COLUMNS = [
        'reactions' => 'reactions_count',
        'comments' => 'comments_count',
        'views' => 'views_count',
        'shares' => 'shares_count',
        'saves' => 'saves_count',
        'reach' => 'reach_count',
        'watch_time_minutes' => 'watch_time_milliseconds',
        'average_watch_time_seconds' => 'average_watch_time_milliseconds',
    ];

    public function __construct(
        private readonly ResolveAnalyticsAccountKey $accountKey,
        private readonly QueryLatestPublicationSnapshots $latestSnapshots,
    ) {}

    /** @return list<string> */
    public function handle(SocialAccount $channel, ?string $accountKey = null): array
    {
        $accountKey ??= $this->accountKey->for($channel);
        $query = $this->latestSnapshots->execute($channel->workspace_id, [$accountKey]);

        foreach (self::COLUMNS as $metric => $column) {
            $query->selectRaw("COUNT(metric.{$column}) as {$metric}");
        }

        $counts = $query
            ->selectRaw('SUM(CASE WHEN metric.engagement_count IS NOT NULL AND metric.exposure_count > 0 THEN 1 ELSE 0 END) as engagement_rate')
            ->first();
        $available = ['followers' => true, 'posts' => true];

        foreach ([...array_keys(self::COLUMNS), 'engagement_rate'] as $metric) {
            $available[$metric] = (int) data_get($counts, $metric, 0) > 0;
        }

        $available['follows_gained'] = $channel->platform->reportsPublicationFollows() && $this->hasFollows($channel, $accountKey);

        return array_values(array_filter(ChannelMetrics::ORDER, fn (string $metric): bool => data_get($available, $metric, false)));
    }

    private function hasFollows(SocialAccount $channel, string $accountKey): bool
    {
        $found = false;

        $this->latestSnapshots->execute($channel->workspace_id, [$accountKey])
            ->whereNotNull('metric.metrics')
            ->select(['metric.id', 'metric.metrics'])
            ->chunkById(500, function (Collection $rows) use (&$found): bool {
                $found = $rows->contains(function (object $row): bool {
                    $follows = data_get(json_decode((string) $row->metrics, true), 'follows');

                    return data_get($follows, 'availability') === MetricAvailability::Available->value
                        && is_numeric(data_get($follows, 'value'));
                });

                return ! $found;
            }, 'metric.id', 'id');

        return $found;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Dto\Analytics\PublicationFilter;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Analytics\ChannelMetrics;
use App\Support\Analytics\EngagementRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class ListChannelPublicationPerformance
{
    /** The ranking stops at the best posts; the rest of the period is not listed. */
    public const int TOP_POSTS = 50;

    /** The insights page lists the ranking ten posts per numbered page. */
    public const int INSIGHTS_PAGE_SIZE = 10;

    public function __construct(
        private readonly ResolveAnalyticsAccountKey $accountKey,
        private readonly QueryLatestPublicationSnapshots $latestSnapshots,
    ) {}

    public function handle(SocialAccount $channel, DateRange $range, string $sort, ?string $accountKey = null, ?PublicationFilter $filter = null, ?int $page = null): LengthAwarePaginator
    {
        return $this->paginate($channel, $range, $sort, $accountKey, $filter, (int) config('app.pagination.default'), $page);
    }

    public function insightsPage(SocialAccount $channel, DateRange $range, string $sort, string $accountKey, PublicationFilter $filter, int $page): LengthAwarePaginator
    {
        return $this->paginate($channel, $range, $sort, $accountKey, $filter, self::INSIGHTS_PAGE_SIZE, $page);
    }

    private function paginate(SocialAccount $channel, DateRange $range, string $sort, ?string $accountKey, ?PublicationFilter $filter, int $pageSize, ?int $page): LengthAwarePaginator
    {
        $expression = data_get(ChannelMetrics::SORTS, $sort);

        if (! is_string($expression)) {
            throw new InvalidArgumentException("Unsupported publication sort [{$sort}].");
        }

        $paginator = $this->latestSnapshots
            ->execute($channel->workspace_id, [$accountKey ?? $this->accountKey->for($channel)], $range->startsAt(), $range->endsAt())
            ->leftJoin((new Post)->getTable().' as destination', 'destination.id', '=', 'publication.post_id')
            ->when($filter !== null, fn (Builder $filtered): Builder => $filter->apply($filtered))
            ->select([
                'publication.id', 'publication.post_id', 'publication.excerpt', 'publication.preview_metadata', 'publication.permalink',
                'publication.provider_published_at', 'publication.content_type',
                'metric.reactions_count', 'metric.comments_count', 'metric.views_count', 'metric.shares_count',
                'metric.saves_count', 'metric.reach_count', 'metric.engagement_count', 'metric.exposure_count',
                'metric.impressions_count', 'metric.watch_time_milliseconds', 'metric.average_watch_time_milliseconds', 'metric.metrics',
            ])
            ->orderByRaw("CASE WHEN {$expression} IS NULL THEN 1 ELSE 0 END")
            ->orderByRaw("{$expression} DESC")
            ->orderByDesc('publication.provider_published_at')
            ->orderBy('publication.id')
            ->paginate($pageSize, page: $page);

        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();
        $total = min($paginator->total(), self::TOP_POSTS);
        $top = new LengthAwarePaginator(
            collect($paginator->items())->take(max(0, $total - $offset))->values(),
            $total,
            $paginator->perPage(),
            $paginator->currentPage(),
            $paginator->getOptions(),
        );

        return $top->through(fn (object $row, int $index): array => $this->row($row, $offset + $index + 1));
    }

    /**
     * @return array{id: string, rank: int, excerpt: ?string, thumbnail_url: ?string, permalink: ?string, published_at: string, content_type: ?string, metrics: array{reactions: ?int, comments: ?int, engagement_rate: ?float, views: ?int, impressions: ?int, shares: ?int, reposts: ?int, quotes: ?int, saves: ?int, clicks: ?int, follows_gained: ?int, reach: ?int, watch_time_minutes: ?float, average_watch_time_seconds: ?float}, post_id: ?string}
     */
    private function row(object $row, int $rank): array
    {
        $measured = $row->metrics === null ? [] : (array) json_decode((string) $row->metrics, true);

        return [
            'id' => $row->id,
            'rank' => $rank,
            'excerpt' => $row->excerpt,
            'thumbnail_url' => $this->thumbnail($row->preview_metadata),
            'permalink' => $row->permalink,
            'published_at' => CarbonImmutable::parse($row->provider_published_at, 'UTC')->toIso8601String(),
            'content_type' => $row->content_type,
            'metrics' => [
                'reactions' => $this->integer($row->reactions_count),
                'comments' => $this->integer($row->comments_count),
                'engagement_rate' => EngagementRate::of($row->engagement_count, $row->exposure_count),
                'views' => $this->integer($row->views_count),
                'impressions' => $this->integer($row->impressions_count),
                'shares' => $this->integer($row->shares_count),
                'reposts' => $this->measured($measured, MetricKey::Reposts),
                'quotes' => $this->measured($measured, MetricKey::Quotes),
                'saves' => $this->integer($row->saves_count),
                'clicks' => $this->measured($measured, MetricKey::Clicks) ?? $this->measured($measured, MetricKey::LinkClicks),
                'follows_gained' => $this->measured($measured, MetricKey::Follows),
                'reach' => $this->integer($row->reach_count),
                'watch_time_minutes' => $row->watch_time_milliseconds === null ? null : round((int) $row->watch_time_milliseconds / 60000, 2),
                'average_watch_time_seconds' => $row->average_watch_time_milliseconds === null ? null : round((int) $row->average_watch_time_milliseconds / 1000, 2),
            ],
            'post_id' => $row->post_id,
        ];
    }

    private function thumbnail(?string $previewMetadata): ?string
    {
        if ($previewMetadata === null) {
            return null;
        }

        $url = data_get(json_decode($previewMetadata, true), 'thumbnail_url');

        return is_string($url) && preg_match('/^https:\/\//i', $url) === 1 ? $url : null;
    }

    /** @param  array<string, mixed>  $metrics */
    private function measured(array $metrics, MetricKey $key): ?int
    {
        $metric = data_get($metrics, $key->value);
        $value = data_get($metric, 'value');

        return data_get($metric, 'availability') === MetricAvailability::Available->value && is_numeric($value)
            ? (int) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

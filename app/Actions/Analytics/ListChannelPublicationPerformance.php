<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Support\Analytics\ChannelMetrics;
use App\Support\Analytics\EngagementRate;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class ListChannelPublicationPerformance
{
    public function __construct(
        private readonly ResolveAnalyticsAccountKey $accountKey,
        private readonly QueryLatestPublicationSnapshots $latestSnapshots,
    ) {}

    public function handle(SocialAccount $channel, DateRange $range, string $sort, ?string $accountKey = null): LengthAwarePaginator
    {
        $expression = data_get(ChannelMetrics::SORTS, $sort);

        if (! is_string($expression)) {
            throw new InvalidArgumentException("Unsupported publication sort [{$sort}].");
        }

        $paginator = $this->latestSnapshots
            ->execute($channel->workspace_id, [$accountKey ?? $this->accountKey->for($channel)], $range->start, $range->observedThrough)
            ->leftJoin((new PostPlatform)->getTable().' as destination', 'destination.id', '=', 'publication.post_platform_id')
            ->select([
                'publication.id', 'destination.post_id', 'publication.excerpt', 'publication.preview_metadata', 'publication.permalink',
                'publication.provider_published_at', 'publication.content_type',
                'metric.reactions_count', 'metric.comments_count', 'metric.views_count', 'metric.shares_count',
                'metric.saves_count', 'metric.reach_count', 'metric.engagement_count', 'metric.exposure_count',
            ])
            ->orderByRaw("CASE WHEN {$expression} IS NULL THEN 1 ELSE 0 END")
            ->orderByRaw("{$expression} DESC")
            ->orderByDesc('publication.provider_published_at')
            ->orderBy('publication.id')
            ->paginate((int) config('app.pagination.default'));

        $offset = ($paginator->currentPage() - 1) * $paginator->perPage();

        return $paginator->through(fn (object $row, int $index): array => $this->row($row, $offset + $index + 1));
    }

    /**
     * @return array{id: string, rank: int, excerpt: ?string, thumbnail_url: ?string, permalink: ?string, published_at: string, content_type: ?string, metrics: array{reactions: ?int, comments: ?int, engagement_rate: ?float, views: ?int, shares: ?int, saves: ?int, reach: ?int}, url: ?string}
     */
    private function row(object $row, int $rank): array
    {
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
                'shares' => $this->integer($row->shares_count),
                'saves' => $this->integer($row->saves_count),
                'reach' => $this->integer($row->reach_count),
            ],
            'url' => $row->post_id === null ? null : route('app.posts.index', ['post' => $row->post_id]),
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

    private function integer(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

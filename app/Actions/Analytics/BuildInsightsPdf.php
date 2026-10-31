<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Post\BuildPublishPageProps;
use App\Dto\Analytics\PublicationFilter;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Analytics\ChannelMetrics;
use App\Support\Analytics\Pdf\PdfImages;
use App\Support\Mail\RecipientTime;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

/**
 * The print page of an Insights PDF and its props. The PDF prints the Insights
 * page itself (`insights/Print`, `channels/insights/Print`), so its numbers come
 * from the same actions the two Insights controllers use, computed at once
 * instead of deferred, plus the cover and the user's choices for the export.
 */
class BuildInsightsPdf
{
    /** The performance table prints at most this many columns, to fit the page. */
    public const int MAX_COLUMNS = 6;

    /** The post table prints at most this many metrics next to the post, to fit the page. */
    private const int MAX_POST_COLUMNS = 4;

    private const array DEFAULT_PERFORMANCE_COLUMNS = ['posts', 'reactions', 'comments', 'engagement_rate'];

    /** Post thumbnails print at 48px; twice that keeps them sharp. */
    private const int THUMBNAIL_PIXELS = 96;

    public function __construct(
        private readonly BuildWorkspaceAnalyticsReport $analytics,
        private readonly ResolveAnalyticsRangePreset $presets,
        private readonly ResolveAnalyticsChannelFilter $channelFilter,
        private readonly ResolveAnalyticsAccountKey $accountKeys,
        private readonly ListAvailableChannelMetrics $availableMetrics,
        private readonly BuildChannelMetricSeries $series,
        private readonly BuildFollowerGrowthRateSeries $growthRate,
        private readonly ListChannelPublicationPerformance $performance,
    ) {}

    /**
     * @param  array<string, mixed>  $query  The filters of the Insights page the export was asked from.
     * @param  array{name: string|null, sections: list<string>, columns: list<string>}  $options
     * @return array{component: string, props: array<string, mixed>}
     */
    public function forWorkspace(Workspace $workspace, User $user, array $query, array $options): array
    {
        ['range' => $range, 'selection' => $selection, 'clamped' => $clamped] = $this->presets->selection($query, $user->timezone);
        ['channels' => $channels, 'selected' => $selected, 'keys' => $channelKeys] = $this->channelFilter->execute($workspace, collect(data_get($query, 'channels', [])));
        $report = $this->analytics->forSelection($workspace, $selection, $channelKeys, $clamped, weekStart: $user->week_starts_on);
        $images = app(PdfImages::class);
        $images->warm($this->ownImageUrls(collect((array) data_get($report, 'top_posts', []))->flatten(1)->all()), self::THUMBNAIL_PIXELS);
        $report['top_posts'] = collect((array) data_get($report, 'top_posts', []))
            ->map(fn (array $posts): array => array_map(fn (array $post): array => $this->slimTopPost($post, $images), $posts))
            ->all();
        $single = $selected->count() === 1 ? $selected->first() : null;

        return [
            'component' => 'insights/Print',
            'props' => [
                ...$this->cover($workspace, $user, $options),
                'channelOptions' => $channels,
                'availableMetrics' => blank($single) ? null : $this->availableMetrics->handle($single, data_get($channelKeys, $single->id)),
                'columns' => $this->performanceColumns(data_get($options, 'columns', [])),
                'sort' => (string) data_get($query, 'sort', 'posts'),
                'direction' => (string) data_get($query, 'direction', 'desc'),
                'report' => [
                    ...$report,
                    'filters' => [
                        'range' => $range,
                        'start' => data_get($report, 'range.start'),
                        'end' => data_get($report, 'range.end'),
                        'channels' => $selected->pluck('id')->all(),
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $query  The filters of the channel's Insights page the export was asked from.
     * @param  array{name: string|null, sections: list<string>, columns: list<string>}  $options
     * @return array{component: string, props: array<string, mixed>}
     */
    public function forChannel(SocialAccount $account, User $user, array $query, array $options): array
    {
        $workspace = $account->workspace;
        $accountKey = $this->accountKeys->for($account);
        $filter = PublicationFilter::fromValidated($account->platform, $query);
        ['range' => $range, 'selection' => $selection, 'clamped' => $clamped] = $this->presets->selection($query, $user->timezone);
        ['bounds' => $bounds, 'range' => $current] = $this->analytics->resolveRange($workspace, $selection, [$accountKey], $clamped);
        $availability = $this->availableMetrics->availability($account, $accountKey);
        $metrics = $this->availableMetrics->handle($account, $accountKey, $availability);
        $sort = ChannelMetrics::sortFor(data_get($query, 'sort'), $metrics);

        return [
            'component' => 'channels/insights/Print',
            'props' => [
                ...$this->cover($workspace, $user, $options),
                'channel' => BuildPublishPageProps::channelHeader($account, $user->week_starts_on),
                'availableMetrics' => $metrics,
                'sortableMetrics' => ChannelMetrics::sortable(),
                'columns' => $this->postColumns(data_get($options, 'columns', []), $metrics),
                'report' => $this->analytics->execute($workspace, $current, $bounds, $account, $accountKey, $user->week_starts_on, $filter),
                'filters' => [
                    'range' => $range,
                    'start' => $current->start->toDateString(),
                    'end' => $current->end->toDateString(),
                    'period' => 'current',
                    'sort' => $sort,
                    'labels' => $filter->labelIds,
                    'untagged' => $filter->untagged,
                    'types' => array_map(fn (ContentType $type): string => $type->value, $filter->contentTypes),
                ],
                'metricSeries' => [
                    ...$this->series->handle($account, $accountKey, $current, $user->week_starts_on, $filter, $availability),
                    'growth' => data_get($availability, 'net_followers')
                        ? $this->growthRate->handle($account->workspace_id, $accountKey, now($user->timezone))
                        : null,
                ],
                'publications' => $this->slimPublications($this->performance->printPage($account, $current, $sort, $accountKey, $filter)),
            ],
        ];
    }

    /**
     * The cover: the report's name (the workspace's by default), the logo,
     * when it was made, and which sections to print.
     *
     * @param  array{name: string|null, sections: list<string>, columns: list<string>}  $options
     * @return array{title: string, logoUrl: string|null, generatedAt: string, sections: list<string>}
     */
    private function cover(Workspace $workspace, User $user, array $options): array
    {
        return [
            'title' => (string) (data_get($options, 'name') ?: $workspace->name),
            'logoUrl' => $this->logo($workspace),
            'generatedAt' => RecipientTime::dateTime(now(), $user),
            'sections' => data_get($options, 'sections', []),
        ];
    }

    /**
     * The performance table's columns: the ones the page shows, known to the report, MAX_COLUMNS at most.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function performanceColumns(array $requested): array
    {
        $known = array_values(array_intersect(BuildPublicationAnalyticsReport::PERFORMANCE_METRICS, $requested ?: self::DEFAULT_PERFORMANCE_COLUMNS));

        return array_slice($known, 0, self::MAX_COLUMNS);
    }

    /**
     * The post table's columns: the ones the page shows, else the page's default
     * (the sortable metrics), MAX_POST_COLUMNS at most so the table fits the paper.
     *
     * @param  list<string>  $requested
     * @param  list<string>  $metrics
     * @return list<string>
     */
    private function postColumns(array $requested, array $metrics): array
    {
        $shown = array_values(array_intersect($requested, $metrics))
            ?: array_values(array_intersect(ChannelMetrics::sortable(), $metrics));

        return array_slice($shown, 0, self::MAX_POST_COLUMNS);
    }

    /**
     * A top post with its thumbnail shrunk to the size it prints at. The page
     * shows the post's own image (warmed with the others) or the network's preview.
     *
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private function slimTopPost(array $post, PdfImages $images): array
    {
        if (MediaType::tryFrom((string) data_get($post, 'media.type')) === MediaType::Image) {
            data_set($post, 'media.url', $images->remote(data_get($post, 'media.url'), self::THUMBNAIL_PIXELS) ?? data_get($post, 'media.url'));
        }

        if (filled(data_get($post, 'preview_metadata.thumbnail_url'))) {
            data_set($post, 'preview_metadata.thumbnail_url', $images->remote(data_get($post, 'preview_metadata.thumbnail_url'), self::THUMBNAIL_PIXELS) ?? data_get($post, 'preview_metadata.thumbnail_url'));
        }

        return $post;
    }

    /**
     * The post table with every thumbnail shrunk to the size it prints at,
     * the posts' own images downloaded together first.
     */
    private function slimPublications(LengthAwarePaginator $publications): LengthAwarePaginator
    {
        $images = app(PdfImages::class);
        $images->warm($this->ownImageUrls($publications->items()), self::THUMBNAIL_PIXELS);

        return $publications->through(fn (array $row): array => [
            ...$row,
            'thumbnail_url' => $images->remote(data_get($row, 'thumbnail_url'), self::THUMBNAIL_PIXELS) ?? data_get($row, 'thumbnail_url'),
        ]);
    }

    /**
     * The URLs of the posts' own images, served by our CDN.
     *
     * @param  list<array<string, mixed>>  $posts
     * @return list<string>
     */
    private function ownImageUrls(array $posts): array
    {
        return collect($posts)
            ->filter(fn (array $post): bool => MediaType::tryFrom((string) data_get($post, 'media.type')) === MediaType::Image)
            ->map(fn (array $post): string => (string) data_get($post, 'media.url'))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * The report logo, else the workspace logo, inlined so the cover never
     * waits on the network and a transparent PNG stays transparent.
     */
    private function logo(Workspace $workspace): ?string
    {
        $media = $workspace->getFirstMedia('report_logo') ?? $workspace->getFirstMedia('logo');

        if (blank($media) || ! Storage::exists($media->path)) {
            return null;
        }

        $content = base64_encode((string) Storage::get($media->path));

        return "data:{$media->mime_type};base64,{$content}";
    }
}

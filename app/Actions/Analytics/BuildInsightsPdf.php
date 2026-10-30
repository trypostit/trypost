<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Dto\Analytics\PublicationFilter;
use App\Enums\Analytics\PdfSection;
use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Analytics\ChannelMetrics;
use App\Support\Analytics\Pdf\PdfFormat;
use App\Support\Analytics\Pdf\PdfImages;
use App\Support\Analytics\Pdf\SvgChart;
use App\Support\Mail\RecipientTime;
use Collator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds what the Insights PDF shows, from the same reports as the Insights pages:
 * the workspace report with a part per channel, or one channel's report.
 */
class BuildInsightsPdf
{
    /** Tables show this many metric columns at most, like Buffer's PDF. */
    public const int MAX_COLUMNS = 6;

    /** The workspace PDF details this many channels at most. */
    public const int MAX_CHANNELS = 25;

    /** Top posts listed for each channel of the workspace PDF. */

    /** Metrics that only restate another one the part shows: new followers are the followers' change. */
    private const array RESTATED_METRICS = ['net_followers'];

    private const array SUMMARY_DEFAULTS = ['posts', 'followers', 'reactions', 'comments', 'engagement_rate'];

    private const array DEFAULT_PERFORMANCE_COLUMNS = ['posts', 'reactions', 'comments', 'engagement_rate'];

    private const array POST_TABLE_METRICS = [
        'reactions', 'comments', 'engagement_rate', 'views', 'impressions', 'shares', 'reposts', 'quotes',
        'saves', 'clicks', 'follows_gained', 'reach', 'watch_time_minutes', 'average_watch_time_seconds',
    ];

    private const string BAR_COLOR = '#7c3aed';

    private const int AVATAR_PIXELS = 72;

    private const int THUMBNAIL_PIXELS = 96;

    private PdfImages $images;

    public function __construct(
        private readonly BuildWorkspaceAnalyticsReport $analytics,
        private readonly ResolveAnalyticsRangePreset $presets,
        private readonly ResolveAnalyticsChannelFilter $channelFilter,
        private readonly ResolveAnalyticsAccountKey $accountKeys,
        private readonly ListAvailableChannelMetrics $availableMetrics,
        private readonly BuildChannelMetricSeries $series,
        private readonly ListChannelPublicationPerformance $performance,
    ) {}

    /**
     * @param  array<string, mixed>  $query  The page's filters: range, start, end and channels.
     * @param  array{name: string|null, sections: list<string>, columns: list<string>}  $options
     * @return array<string, mixed>
     */
    public function forWorkspace(Workspace $workspace, User $user, array $query, array $options): array
    {
        $this->images = app(PdfImages::class);
        $format = new PdfFormat(app()->getLocale());
        ['selection' => $selection, 'clamped' => $clamped] = $this->presets->selection($query, $user->timezone);
        ['selected' => $selected, 'keys' => $channelKeys] = $this->channelFilter->execute($workspace, collect(data_get($query, 'channels', [])));
        $accountKeys = $this->analytics->keys($channelKeys);
        ['bounds' => $bounds, 'range' => $range] = $this->analytics->resolveRange($workspace, $selection, $accountKeys, $clamped);
        $report = $this->analytics->forRange($workspace, $range, $bounds, $channelKeys, $user->week_starts_on);
        $single = $selected->count() === 1 ? $selected->first() : null;
        $summaryMetrics = blank($single) ? self::SUMMARY_DEFAULTS : $this->availableMetrics->handle($single, data_get($channelKeys, $single->id));
        $sections = data_get($options, 'sections', []);
        $performance = $this->sortPerformance((array) data_get($report, 'performance', []), (string) data_get($query, 'sort', 'posts'), (string) data_get($query, 'direction', 'desc'));
        $channelAccounts = $this->accountsInOrder($workspace, $performance);

        return [
            ...$this->cover($workspace, $user, $report, $options, $format, array_map(fn (array $row): array => $this->identity($row), $performance)),
            'scope' => 'workspace',
            'sections' => $sections,
            'summary' => in_array(PdfSection::Summary->value, $sections, true) ? $this->summary($report, $summaryMetrics, $format) : null,
            'top_reactions' => in_array(PdfSection::TopPostsByReactions->value, $sections, true) ? $this->topPosts((array) data_get($report, 'top_posts.reactions', []), 'reactions', $user, $format) : null,
            'top_comments' => in_array(PdfSection::TopPostsByComments->value, $sections, true) ? $this->topPosts((array) data_get($report, 'top_posts.comments', []), 'comments', $user, $format) : null,
            'performance' => in_array(PdfSection::Performance->value, $sections, true) ? $this->performanceTable($performance, data_get($options, 'columns', []), $format) : null,
            'channels' => in_array(PdfSection::Channels->value, $sections, true)
                ? $this->channelParts(
                    $workspace,
                    $user,
                    $range,
                    $bounds,
                    $channelAccounts->take(self::MAX_CHANNELS),
                    $format,
                    in_array(PdfSection::Performance->value, $sections, true) ? $this->performanceColumns(data_get($options, 'columns', [])) : [],
                )
                : null,
            'channels_total' => $channelAccounts->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $query  The page's filters: range, start, end, labels, untagged, types and sort.
     * @param  array{name: string|null, sections: list<string>, columns: list<string>}  $options
     * @return array<string, mixed>
     */
    public function forChannel(SocialAccount $account, User $user, array $query, array $options): array
    {
        $this->images = app(PdfImages::class);
        $workspace = $account->workspace;
        $format = new PdfFormat(app()->getLocale());
        $filter = PublicationFilter::fromValidated($account->platform, $query);
        $accountKey = $this->accountKeys->for($account);
        ['selection' => $selection, 'clamped' => $clamped] = $this->presets->selection($query, $user->timezone);
        ['bounds' => $bounds, 'range' => $range] = $this->analytics->resolveRange($workspace, $selection, [$accountKey], $clamped);
        $report = $this->analytics->execute($workspace, $range, $bounds, $account, $accountKey, $user->week_starts_on, $filter);
        $availability = $this->availableMetrics->availability($account, $accountKey);
        $metrics = $this->availableMetrics->handle($account, $accountKey, $availability);
        $sections = data_get($options, 'sections', []);

        return [
            ...$this->cover($workspace, $user, $report, $options, $format, [$this->accountIdentity($account)]),
            'scope' => 'channel',
            'sections' => $sections,
            'summary' => in_array(PdfSection::Summary->value, $sections, true) ? $this->summary($report, array_values(array_diff($metrics, self::RESTATED_METRICS)), $format) : null,
            'metrics' => in_array(PdfSection::Metrics->value, $sections, true)
                ? $this->charts($this->series->handle($account, $accountKey, $range, $user->week_starts_on, $filter, $availability), $format)
                : null,
            'posts' => in_array(PdfSection::Posts->value, $sections, true)
                ? [
                    ...$this->postsTable($account, $accountKey, $range, $filter, $metrics, ChannelMetrics::sortFor(data_get($query, 'sort'), $metrics), data_get($options, 'columns', []), $user, $format),
                    'total' => (int) data_get($report, 'summary.posts.value', 0),
                ]
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array{name: string|null, sections: list<string>, columns: list<string>}  $options
     * @param  list<array<string, mixed>>  $channels
     * @return array<string, mixed>
     */
    private function cover(Workspace $workspace, User $user, array $report, array $options, PdfFormat $format, array $channels): array
    {
        return [
            'locale' => app()->getLocale(),
            'direction' => Locale::tryFrom(app()->getLocale())?->direction() ?? 'ltr',
            'title' => filled(data_get($options, 'name')) ? data_get($options, 'name') : $workspace->name,
            'logo' => $this->logo($workspace),
            'range' => $format->span(data_get($report, 'range')),
            'previous_range' => $format->span(data_get($report, 'previous_range')),
            'generated_at' => RecipientTime::dateTime(now(), $user),
            'cover_channels' => $channels,
        ];
    }

    /**
     * The logo uploaded for reports, else the workspace logo, inlined so the PDF
     * needs no network.
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

    /**
     * @param  array<string, mixed>  $report
     * @param  list<string>  $metrics
     * @return list<array{label: string, value: string, change: array{label: string, direction: string}|null}>
     */
    private function summary(array $report, array $metrics, PdfFormat $format): array
    {
        return array_map(fn (string $metric): array => [
            'label' => __("analytics.channel.metrics.{$metric}.label"),
            'value' => $format->metric(data_get($report, "summary.{$metric}.value"), $metric),
            'change' => $format->change(data_get($report, "summary.{$metric}.change"), $metric),
        ], $metrics);
    }

    /**
     * @param  list<array<string, mixed>>  $posts
     * @return list<array<string, mixed>>
     */
    private function topPosts(array $posts, string $metric, User $user, PdfFormat $format): array
    {
        return array_values(array_map(fn (array $post, int $index): array => [
            'rank' => $index + 1,
            'value' => $format->metric(data_get($post, $metric) ?? 0, $metric),
            'metric' => __("analytics.dashboard.{$metric}"),
            'account' => $this->identity($post),
            'date' => $format->day((string) data_get($post, 'published_at'), $user->timezone),
            'excerpt' => Str::limit((string) (data_get($post, 'excerpt') ?: __('analytics.dashboard.no_excerpt')), 220),
            'thumbnail' => $this->thumbnail((array) data_get($post, 'media'), data_get($post, 'preview_metadata.thumbnail_url')),
            'via' => data_get($post, 'origin') === 'trypost' ? 'TryPost' : $this->network(data_get($post, 'platform')),
        ], $posts, array_keys($posts)));
    }

    /**
     * Orders the channels the way the Performance table on the page does, which
     * also decides the order of the channel parts and which fit in the cap.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortPerformance(array $rows, string $sort, string $direction): array
    {
        $name = fn (array $row): string => (string) (data_get($row, 'username') ?? data_get($row, 'name') ?? data_get($row, 'platform'));
        $collator = new Collator(App::getLocale());

        usort($rows, function (array $left, array $right) use ($sort, $direction, $name, $collator): int {
            if ($sort === 'channel') {
                $order = (int) $collator->compare($name($left), $name($right));
                $order = $direction === 'desc' ? -$order : $order;
            } else {
                $leftValue = data_get($left, "{$sort}.value");
                $rightValue = data_get($right, "{$sort}.value");
                $order = match (true) {
                    blank($leftValue) && blank($rightValue) => 0,
                    blank($leftValue) => 1,
                    blank($rightValue) => -1,
                    default => $direction === 'asc' ? $leftValue <=> $rightValue : $rightValue <=> $leftValue,
                };
            }

            return $order ?: strcmp((string) data_get($left, 'social_account_key'), (string) data_get($right, 'social_account_key'));
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $requested
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, hidden: int}
     */
    private function performanceTable(array $rows, array $requested, PdfFormat $format): array
    {
        $chosen = $this->chosenPerformanceColumns($requested);
        $columns = $this->performanceColumns($requested);

        return [
            'columns' => array_map(fn (string $metric): string => __("analytics.channel.metrics.{$metric}.label"), $columns),
            'rows' => array_map(fn (array $row): array => [
                'account' => $this->identity($row),
                'cells' => array_map(fn (string $metric): array => [
                    'value' => $format->metric(data_get($row, "{$metric}.value"), $metric),
                    'change' => $format->change(data_get($row, "{$metric}.change"), $metric),
                ], $columns),
            ], $rows),
            'hidden' => count($chosen) - count($columns),
        ];
    }

    /**
     * The connected channels behind the Performance rows, in the rows' order. A row
     * is keyed by the channel's analytics identity, which outlives a reconnect, so
     * it is matched through that key and never by account id.
     *
     * @param  list<array<string, mixed>>  $performance
     * @return Collection<int, SocialAccount>
     */
    private function accountsInOrder(Workspace $workspace, array $performance): Collection
    {
        $accounts = $workspace->socialAccounts()->includedInAnalytics()->get();
        $byKey = collect($this->accountKeys->forMany($accounts))
            ->mapWithKeys(fn (string $key, string $id): array => [$key => $accounts->firstWhere('id', $id)]);

        return collect($performance)
            ->map(fn (array $row): ?SocialAccount => $byKey->get((string) data_get($row, 'social_account_key')))
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function chosenPerformanceColumns(array $requested): array
    {
        return array_values(array_intersect(BuildPublicationAnalyticsReport::PERFORMANCE_METRICS, $requested ?: self::DEFAULT_PERFORMANCE_COLUMNS));
    }

    /**
     * The metrics the Performance table prints, at most MAX_COLUMNS.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function performanceColumns(array $requested): array
    {
        return array_slice($this->chosenPerformanceColumns($requested), 0, self::MAX_COLUMNS);
    }

    /**
     * Each channel's own part, without what the report already shows: the
     * metrics of the Performance table and the new followers, which only
     * restate the followers' change. Posts stay in the workspace's top lists.
     *
     * @param  array{min: ?string, max: ?string}  $bounds
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  list<string>  $shownMetrics
     * @return list<array<string, mixed>>
     */
    private function channelParts(Workspace $workspace, User $user, DateRange $range, array $bounds, Collection $accounts, PdfFormat $format, array $shownMetrics): array
    {
        $skippedMetrics = [...$shownMetrics, ...self::RESTATED_METRICS];

        return $accounts
            ->map(function (SocialAccount $account) use ($workspace, $user, $range, $bounds, $format, $skippedMetrics): array {
                $accountKey = $this->accountKeys->for($account);
                $availability = $this->availableMetrics->availability($account, $accountKey);
                $report = $this->analytics->execute($workspace, $range, $bounds, $account, $accountKey, $user->week_starts_on);

                return [
                    'account' => $this->accountIdentity($account),
                    'summary' => $this->summary($report, array_values(array_diff($this->availableMetrics->handle($account, $accountKey, $availability), $skippedMetrics)), $format),
                    'charts' => $this->charts($this->series->handle($account, $accountKey, $range, $user->week_starts_on, null, $availability), $format),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One chart per metric of the channel's series, followers as a line, the rest
     * as bars; new followers are left out, the followers line already shows them.
     *
     * @param  array<string, mixed>  $series
     * @return list<array{title: string, total: string, svg: string}>
     */
    private function charts(array $series, PdfFormat $format): array
    {
        $points = (array) data_get($series, 'current', []);
        $labels = array_map(fn (array $point): string => $format->shortDay((string) data_get($point, 'start')), $points);

        return array_values(array_map(function (string $metric) use ($points, $labels, $series, $format): array {
            $kind = $metric === 'followers' ? 'line' : 'bar';

            return [
                'title' => __("analytics.channel.series.{$metric}"),
                'total' => $format->metric(data_get($series, "totals.current.{$metric}"), $metric),
                'svg' => SvgChart::timeSeries(
                    array_map(fn (array $point): int|float|null => data_get($point, "values.{$metric}"), $points),
                    $labels,
                    $kind,
                    self::BAR_COLOR,
                    fn (int|float $value): string => $kind === 'line' ? $format->number($value) : $format->metric($value, $metric),
                ),
            ];
        }, array_values(array_diff((array) data_get($series, 'metrics', []), self::RESTATED_METRICS))));
    }

    /**
     * @param  list<string>  $available
     * @param  list<string>  $requested
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, hidden: int}
     */
    private function postsTable(SocialAccount $account, string $accountKey, DateRange $range, PublicationFilter $filter, array $available, string $sort, array $requested, User $user, PdfFormat $format): array
    {
        $choosable = array_values(array_intersect(self::POST_TABLE_METRICS, $available));
        $defaults = array_values(array_intersect($choosable, ChannelMetrics::sortable()));
        $picked = array_values(array_intersect($choosable, $requested));
        $chosen = array_values(array_filter($choosable, fn (string $metric): bool => in_array($metric, $picked ?: $defaults, true) || $metric === $sort));
        $columns = array_slice($chosen, 0, self::MAX_COLUMNS);
        $rows = [];
        $page = 1;

        do {
            $paginator = $this->performance->handle($account, $range, $sort, $accountKey, $filter, $page);

            foreach ($paginator->items() as $row) {
                $rows[] = [
                    'rank' => data_get($row, 'rank'),
                    'excerpt' => Str::limit((string) (data_get($row, 'excerpt') ?: __('analytics.dashboard.no_excerpt')), 140),
                    'thumbnail' => $this->thumbnail((array) data_get($row, 'media'), data_get($row, 'thumbnail_url')),
                    'date' => $format->day((string) data_get($row, 'published_at'), $user->timezone),
                    'type' => transform(data_get($row, 'content_type'), fn (string $type): string => __("analytics.detail.content_types.{$type}")),
                    'cells' => array_map(fn (string $metric): string => $format->metric(data_get($row, "metrics.{$metric}"), $metric), $columns),
                ];
            }

            $page++;
        } while ($page <= $paginator->lastPage());

        return [
            'columns' => array_map(fn (string $metric): string => __("analytics.channel.metrics.{$metric}.label"), $columns),
            'rows' => $rows,
            'hidden' => count($chosen) - count($columns),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{name: string, handle: string|null, avatar: string|null, network: string}
     */
    private function identity(array $row): array
    {
        $username = data_get($row, 'username');
        $name = data_get($row, 'name');

        return [
            'name' => (string) ($name ?: (filled($username) ? "@{$username}" : $this->network(data_get($row, 'platform')))),
            'handle' => filled($name) && filled($username) ? "@{$username}" : null,
            'avatar' => $this->images->remote(data_get($row, 'avatar_url'), self::AVATAR_PIXELS),
            'network' => $this->network(data_get($row, 'platform')),
        ];
    }

    /**
     * @return array{name: string, handle: string|null, avatar: string|null, network: string}
     */
    private function accountIdentity(SocialAccount $account): array
    {
        return $this->identity([
            'username' => $account->username,
            'name' => $account->display_name,
            'avatar_url' => $account->avatar_url,
            'platform' => $account->platform->value,
        ]);
    }

    /**
     * The post's own image read from our disk, else the link preview image.
     *
     * @param  array<string, mixed>  $media
     */
    private function thumbnail(array $media, mixed $previewUrl): ?string
    {
        $type = MediaType::tryFrom((string) data_get($media, 'type')) ?? MediaType::classify(data_get($media, 'mime_type'), data_get($media, 'path'));

        if ($type === MediaType::Image && filled(data_get($media, 'path'))) {
            return $this->images->stored((string) data_get($media, 'path'), self::THUMBNAIL_PIXELS);
        }

        return $this->images->remote(filled($previewUrl) ? (string) $previewUrl : null, self::THUMBNAIL_PIXELS);
    }

    private function network(mixed $platform): string
    {
        return Platform::tryFrom((string) $platform)?->label() ?? (string) $platform;
    }
}

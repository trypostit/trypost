<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\SocialAccount\Platform;
use App\Models\Workspace;
use App\Support\Analytics\EngagementRate;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Turns the Insights report the page shows into translated export sections.
 */
class BuildInsightsExport
{
    private const array SUMMARY_DEFAULTS = ['posts', 'followers', 'reactions', 'comments', 'engagement_rate'];

    private const array POST_METRICS = [
        'reactions', 'comments', 'engagement_rate', 'reposts', 'impressions', 'clicks', 'views', 'shares', 'saves', 'reach',
    ];

    public function __construct(private readonly BuildPublicationAnalyticsReport $publications) {}

    /**
     * @param  array<string, mixed>  $report  The report built for the page.
     * @param  list<string>|null  $accountKeys
     * @param  list<string>  $labelIds
     * @return array{title: string, meta: list<array{0: string, 1: string}>, sections: list<array{title: string, headers: list<string>, rows: iterable<list<int|float|string|null>>}>}
     */
    public function execute(Workspace $workspace, array $report, DateRange $range, ?array $accountKeys, array $labelIds, bool $untagged, string $timezone): array
    {
        return [
            'title' => __('analytics.title'),
            'meta' => [
                [__('analytics.insights.export.range'), $this->span(data_get($report, 'range'))],
                [__('analytics.insights.export.compared_to'), $this->span(data_get($report, 'previous_range'))],
                [__('analytics.insights.export.generated_at'), now($timezone)->format('Y-m-d H:i')],
            ],
            'sections' => [
                $this->summary($report),
                $this->performance($report),
                $this->followers($report),
                [
                    'title' => __('analytics.dashboard.posts'),
                    'headers' => [
                        __('analytics.insights.export.published_at'),
                        __('analytics.dashboard.channel'),
                        __('analytics.insights.export.network'),
                        __('analytics.insights.export.content_type'),
                        __('analytics.insights.export.text'),
                        __('analytics.insights.export.link'),
                        ...array_map(fn (string $metric): string => $this->metricLabel($metric), self::POST_METRICS),
                    ],
                    'rows' => $this->posts($workspace, $range, $accountKeys, $labelIds, $untagged, $timezone),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{title: string, headers: list<string>, rows: list<list<int|float|string|null>>}
     */
    private function summary(array $report): array
    {
        $rows = [];

        foreach ((array) data_get($report, 'summary', []) as $metric => $comparison) {
            if (! in_array($metric, self::SUMMARY_DEFAULTS, true) && data_get($comparison, 'value') === null && data_get($comparison, 'previous') === null) {
                continue;
            }

            $rows[] = [
                $this->metricLabel($metric),
                data_get($comparison, 'value'),
                data_get($comparison, 'previous'),
                data_get($comparison, 'change'),
            ];
        }

        return [
            'title' => __('analytics.dashboard.summary'),
            'headers' => [
                __('analytics.insights.export.metric'),
                __('analytics.insights.export.value'),
                __('analytics.insights.export.previous'),
                __('analytics.insights.export.change'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{title: string, headers: list<string>, rows: list<list<int|float|string|null>>}
     */
    private function performance(array $report): array
    {
        return [
            'title' => __('analytics.dashboard.performance'),
            'headers' => [
                __('analytics.dashboard.channel'),
                __('analytics.insights.export.network'),
                ...array_map(fn (string $metric): string => $this->metricLabel($metric), BuildPublicationAnalyticsReport::PERFORMANCE_METRICS),
            ],
            'rows' => array_map(fn (array $row): array => [
                $this->accountName($row),
                $this->network(data_get($row, 'platform')),
                ...array_map(fn (string $metric): int|float|null => data_get($row, "{$metric}.value"), BuildPublicationAnalyticsReport::PERFORMANCE_METRICS),
            ], (array) data_get($report, 'performance', [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{title: string, headers: list<string>, rows: list<list<int|float|string|null>>}
     */
    private function followers(array $report): array
    {
        return [
            'title' => __('analytics.dashboard.followers'),
            'headers' => [
                __('analytics.dashboard.channel'),
                __('analytics.insights.export.network'),
                __('analytics.dashboard.followers'),
                __('analytics.dashboard.chart_growth'),
            ],
            'rows' => array_map(fn (array $account): array => [
                $this->accountName($account),
                $this->network(data_get($account, 'platform')),
                data_get($account, 'value'),
                data_get($account, 'growth'),
            ], (array) data_get($report, 'followers.accounts', [])),
        ];
    }

    /**
     * @param  list<string>|null  $accountKeys
     * @param  list<string>  $labelIds
     * @return Generator<int, list<int|float|string|null>>
     */
    private function posts(Workspace $workspace, DateRange $range, ?array $accountKeys, array $labelIds, bool $untagged, string $timezone): Generator
    {
        $rows = $this->publications->query($workspace, $range->start, $range->observedThrough, $accountKeys, $labelIds, $untagged)
            ->orderByDesc('publication.provider_published_at')
            ->orderBy('publication.id')
            ->cursor();

        foreach ($rows as $row) {
            $metrics = $row->metrics === null ? [] : (array) json_decode((string) $row->metrics, true);
            $values = [
                'reactions' => $this->integer($row->reactions_count),
                'comments' => $this->integer($row->comments_count),
                'engagement_rate' => EngagementRate::of($row->engagement_count, $row->exposure_count),
                'reposts' => $this->measured($metrics, MetricKey::Reposts),
                'impressions' => $this->integer($row->impressions_count),
                'clicks' => $this->measured($metrics, MetricKey::Clicks) ?? $this->measured($metrics, MetricKey::LinkClicks),
                'views' => $this->integer($row->views_count),
                'shares' => $this->integer($row->shares_count),
                'saves' => $this->integer($row->saves_count),
                'reach' => $this->integer($row->reach_count),
            ];

            yield [
                CarbonImmutable::parse($row->provider_published_at, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i'),
                $this->accountName(['username' => $row->account_username, 'name' => $row->account_display_name, 'platform' => $row->platform]),
                $this->network($row->platform),
                __("analytics.detail.content_types.{$row->content_type}"),
                $row->excerpt,
                $row->permalink,
                ...array_values($values),
            ];
        }
    }

    private function metricLabel(string $metric): string
    {
        return __("analytics.channel.metrics.{$metric}.label");
    }

    /** @param array<string, mixed> $account */
    private function accountName(array $account): string
    {
        $username = data_get($account, 'username');

        return filled($username) ? (string) $username : (string) (data_get($account, 'name') ?: $this->network(data_get($account, 'platform')));
    }

    private function network(mixed $platform): string
    {
        return Platform::tryFrom((string) $platform)?->label() ?? (string) $platform;
    }

    /** @param array{start: string, end: string}|null $range */
    private function span(?array $range): string
    {
        return $range === null ? '' : data_get($range, 'start').' – '.data_get($range, 'end');
    }

    /** @param array<string, mixed> $metrics */
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

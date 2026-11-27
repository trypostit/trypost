<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Admin\QuotaSource;
use App\Services\Admin\Quotas\XUsageReader;
use App\Services\Admin\Quotas\YouTubeQuotaReader;
use App\Support\Admin\QuotaSnapshots;
use App\Support\Admin\XPricing;
use Carbon\CarbonImmutable;

class BuildQuotaCards
{
    /**
     * The quota cards: the latest reading `admin:sync-platform-quotas` kept of
     * each provider, exactly as the provider reported it, plus, for X, an
     * estimate of the daily cost from its public prices, labelled as such.
     * X's daily figures are cut to the UTC days between `$start` and `$end`;
     * YouTube's quota is today's. Never a network call here.
     *
     * @return array{youtube: array<string, mixed>, x: array<string, mixed>}
     */
    public static function execute(string $start, string $end): array
    {
        $youtube = QuotaSnapshots::latest(QuotaSource::YouTube);
        $x = QuotaSnapshots::latest(QuotaSource::X);

        return [
            'youtube' => [
                'configured' => app(YouTubeQuotaReader::class)->isConfigured(),
                'taken_at' => data_get($youtube, 'taken_at'),
                'since' => data_get($youtube, 'data.since'),
                'quotas' => data_get($youtube, 'data.quotas', []),
            ],
            'x' => [
                'configured' => app(XUsageReader::class)->isConfigured(),
                'taken_at' => data_get($x, 'taken_at'),
                'credits' => data_get($x, 'data.credits'),
                'usage' => self::xUsageBetween(data_get($x, 'data.usage'), $start, $end),
                'estimate' => self::xEstimate(data_get($x, 'data.usage.daily_project_usage', []), $start, $end),
                'prices' => [
                    'read' => XPricing::POST_READ,
                    'create' => XPricing::POST_CREATE,
                    'create_with_link' => XPricing::POST_CREATE_WITH_URL,
                ],
            ],
        ];
    }

    /**
     * X's usage with its daily lists cut to the range.
     *
     * @param  array<string, mixed>|null  $usage
     * @return array<string, mixed>|null
     */
    private static function xUsageBetween(?array $usage, string $start, string $end): ?array
    {
        if ($usage === null) {
            return null;
        }

        $within = fn (array $days): array => collect($days)
            ->filter(fn (array $day): bool => data_get($day, 'date') >= $start && data_get($day, 'date') <= $end)
            ->values()
            ->all();

        data_set($usage, 'daily_project_usage', $within(data_get($usage, 'daily_project_usage', [])));

        if (filled(data_get($usage, 'daily_client_app_usage'))) {
            data_set($usage, 'daily_client_app_usage', collect(data_get($usage, 'daily_client_app_usage'))
                ->map(fn (array $app): array => [...$app, 'usage' => $within(data_get($app, 'usage', []))])
                ->all());
        }

        return $usage;
    }

    /**
     * Every UTC day of the range, with the Posts X read on it (zero when X
     * reported none).
     *
     * @param  list<array{date: string, usage: int}>  $dailyUsage
     * @return list<array<string, mixed>>
     */
    private static function xEstimate(array $dailyUsage, string $start, string $end): array
    {
        $first = CarbonImmutable::parse($start, 'UTC');
        $days = collect(range(0, (int) $first->diffInDays(CarbonImmutable::parse($end, 'UTC'))))
            ->map(fn (int $offset): string => $first->addDays($offset)->toDateString())
            ->all();

        return BuildXCostEstimate::execute($days, collect($dailyUsage)->pluck('usage', 'date')->all());
    }
}

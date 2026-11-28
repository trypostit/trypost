<?php

declare(strict_types=1);

namespace App\Actions\Quota;

use App\Dto\Analytics\DateRange;
use App\Enums\Quota\QuotaSource;
use App\Services\Quota\XUsageReader;
use App\Services\Quota\YouTubeQuotaReader;
use App\Support\Quota\QuotaSnapshots;
use App\Support\Quota\XPricing;

class BuildQuotaCards
{
    /**
     * The quota cards: the latest reading `quotas:sync` kept of
     * each provider, exactly as the provider reported it, plus, for X, an
     * estimate of the daily cost from its public prices, labelled as such.
     * X's daily figures are cut to the range's days (UTC days, as X counts
     * them); YouTube's quota is today's. Never a network call here.
     *
     * @return array{youtube: array<string, mixed>, x: array<string, mixed>}
     */
    public static function execute(DateRange $range): array
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
                'usage' => self::xUsageBetween(data_get($x, 'data.usage'), $range),
                'estimate' => self::xEstimate(data_get($x, 'data.usage.daily_project_usage', []), $range),
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
    private static function xUsageBetween(?array $usage, DateRange $range): ?array
    {
        if (blank($usage)) {
            return null;
        }

        $rangeDays = collect($range->calendarDays());
        $within = fn (array $days): array => collect($days)
            ->filter(fn (array $day): bool => $rangeDays->contains(data_get($day, 'date')))
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
    private static function xEstimate(array $dailyUsage, DateRange $range): array
    {
        return BuildXCostEstimate::execute($range->calendarDays(), collect($dailyUsage)->pluck('usage', 'date')->all());
    }
}

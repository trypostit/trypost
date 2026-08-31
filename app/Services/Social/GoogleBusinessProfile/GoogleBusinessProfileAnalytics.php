<?php

declare(strict_types=1);

namespace App\Services\Social\GoogleBusinessProfile;

use App\Models\GoogleBusinessProfileLocation;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Carbon\CarbonInterface;

class GoogleBusinessProfileAnalytics
{
    private const DAILY_METRICS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'BUSINESS_CONVERSATIONS',
        'BUSINESS_DIRECTION_REQUESTS',
        'CALL_CLICKS',
        'WEBSITE_CLICKS',
        'BUSINESS_BOOKINGS',
        'BUSINESS_FOOD_ORDERS',
        'BUSINESS_FOOD_MENU_CLICKS',
    ];

    public function __construct(private readonly GoogleBusinessProfileApi $api) {}

    /** @return array<int, array{label: string, value: int|string}> */
    public function getMetrics(
        SocialAccount $account,
        ?CarbonInterface $since = null,
        ?CarbonInterface $until = null,
        ?GoogleBusinessProfileLocation $location = null,
    ): array {
        $this->refreshTokenIfNeeded($account);

        $since ??= now()->subDays(30);
        $until ??= now();
        $totals = array_fill_keys(self::DAILY_METRICS, 0);
        /** @var array<string, array{value: int, estimated: bool}> $keywords */
        $keywords = [];

        $locations = $location
            ? collect([$location])
            : $account->googleBusinessProfileLocations()->where('is_selected', true)->get();

        foreach ($locations as $location) {
            $response = $this->api->performance($location, [
                'dailyMetrics' => self::DAILY_METRICS,
                'dailyRange.start_date.year' => $since->year,
                'dailyRange.start_date.month' => $since->month,
                'dailyRange.start_date.day' => $since->day,
                'dailyRange.end_date.year' => $until->year,
                'dailyRange.end_date.month' => $until->month,
                'dailyRange.end_date.day' => $until->day,
            ]);

            foreach ((array) data_get($response, 'multiDailyMetricTimeSeries', []) as $seriesGroup) {
                foreach ((array) data_get($seriesGroup, 'dailyMetricTimeSeries', []) as $series) {
                    $metric = (string) data_get($series, 'dailyMetric');
                    $totals[$metric] = ($totals[$metric] ?? 0) + collect(data_get($series, 'timeSeries.datedValues', []))
                        ->sum(fn (array $point): int => (int) data_get($point, 'value', 0));
                }
            }

            foreach ($this->api->searchKeywords($location, [
                'monthlyRange.start_month.year' => $since->year,
                'monthlyRange.start_month.month' => $since->month,
                'monthlyRange.end_month.year' => $until->year,
                'monthlyRange.end_month.month' => $until->month,
            ]) as $keyword) {
                $term = (string) data_get($keyword, 'searchKeyword');
                $value = data_get($keyword, 'insightsValue.value');
                $threshold = data_get($keyword, 'insightsValue.threshold');
                $keywords[$term] ??= ['value' => 0, 'estimated' => false];
                $keywords[$term]['value'] += (int) ($value ?? $threshold ?? 0);
                $keywords[$term]['estimated'] = $keywords[$term]['estimated'] || $threshold !== null;
            }
        }

        uasort($keywords, fn (array $left, array $right): int => $right['value'] <=> $left['value']);

        return [
            ['label' => 'Profile impressions', 'value' => $totals['BUSINESS_IMPRESSIONS_DESKTOP_MAPS'] + $totals['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH'] + $totals['BUSINESS_IMPRESSIONS_MOBILE_MAPS'] + $totals['BUSINESS_IMPRESSIONS_MOBILE_SEARCH']],
            ['label' => 'Calls', 'value' => $totals['CALL_CLICKS']],
            ['label' => 'Website clicks', 'value' => $totals['WEBSITE_CLICKS']],
            ['label' => 'Direction requests', 'value' => $totals['BUSINESS_DIRECTION_REQUESTS']],
            ['label' => 'Conversations', 'value' => $totals['BUSINESS_CONVERSATIONS']],
            ['label' => 'Bookings', 'value' => $totals['BUSINESS_BOOKINGS']],
            ['label' => 'Food orders', 'value' => $totals['BUSINESS_FOOD_ORDERS']],
            ['label' => 'Menu clicks', 'value' => $totals['BUSINESS_FOOD_MENU_CLICKS']],
            ...collect($keywords)->take(5)->map(
                fn (array $keyword, string $term): array => [
                    'label' => "Search: {$term}",
                    'value' => $keyword['estimated'] ? '<'.$keyword['value'] : $keyword['value'],
                ],
            )->values()->all(),
        ];
    }

    /** @return array<int, array{label: string, value: int}> */
    public function fetchPostMetrics(PostPlatform $postPlatform): array
    {
        $account = $postPlatform->socialAccount;
        $location = $postPlatform->googleBusinessProfileLocation;
        if (! $account || ! $location || ! $postPlatform->platform_post_id) {
            return ['unsupported' => true, 'reason' => 'missing_account_or_location'];
        }

        $this->refreshTokenIfNeeded($account);

        $response = $this->api->localPostInsights(
            $location,
            $postPlatform->platform_post_id,
        );
        $metricValues = (array) data_get($response, 'localPostMetrics.0.metricValues', []);
        $metrics = collect($metricValues)->mapWithKeys(fn (array $metric): array => [
            data_get($metric, 'metric') => (int) data_get($metric, 'totalValue.value', 0),
        ]);

        return [
            ['label' => 'Views in Google Search', 'value' => (int) $metrics->get('LOCAL_POST_VIEWS_SEARCH', 0)],
            ['label' => 'Call-to-action clicks', 'value' => (int) $metrics->get('LOCAL_POST_ACTIONS_CALL_TO_ACTION', 0)],
        ];
    }

    private function refreshTokenIfNeeded(SocialAccount $account): void
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }
    }
}

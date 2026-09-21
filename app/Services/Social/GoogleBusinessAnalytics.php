<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Support\GoogleBusinessResourceName;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GoogleBusinessAnalytics
{
    use HasSocialHttpClient;

    /** Metrics every Business Profile reports. */
    private const METRICS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 'analytics.metrics.desktop_search_impressions',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' => 'analytics.metrics.mobile_search_impressions',
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS' => 'analytics.metrics.desktop_map_impressions',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS' => 'analytics.metrics.mobile_map_impressions',
        'WEBSITE_CLICKS' => 'analytics.metrics.website_clicks',
        'CALL_CLICKS' => 'analytics.metrics.call_clicks',
        'BUSINESS_DIRECTION_REQUESTS' => 'analytics.metrics.direction_requests',
        'BUSINESS_CONVERSATIONS' => 'analytics.metrics.conversations',
    ];

    /**
     * Bookings and food metrics stay empty for most business types. Hide a
     * zero so a dentist is not staring at three permanent empty cards.
     */
    private const CONDITIONAL_METRICS = [
        'BUSINESS_BOOKINGS' => 'analytics.metrics.bookings',
        'BUSINESS_FOOD_ORDERS' => 'analytics.metrics.food_orders',
        'BUSINESS_FOOD_MENU_CLICKS' => 'analytics.metrics.food_menu_clicks',
    ];

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.google_business.performance_api');
    }

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(7);
        $until ??= now();

        return $this->rememberSuccessful(
            "analytics:google_business:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}",
            fn (): array|false => $this->fetchMetricsFromApi($account, $since, $until),
        );
    }

    /**
     * Google only aggregates search keywords by month, so a day-level range
     * is widened to the months it touches — the panel labels the period it got.
     *
     * @return list<array{keyword: string, value: int, estimated: bool}>
     */
    public function getSearchKeywords(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subMonth();
        $until ??= now();

        return $this->rememberSuccessful(
            "analytics:google_business:keywords:{$account->id}:{$since->format('Y-m')}:{$until->format('Y-m')}",
            fn (): array|false => $this->fetchSearchKeywordsFromApi($account, $since, $until),
        );
    }

    /**
     * @param  callable(): array|false  $callback
     */
    private function rememberSuccessful(string $key, callable $callback): array
    {
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $value = $callback();

        if ($value === false) {
            return [];
        }

        Cache::put($key, $value, app()->isProduction() ? 3600 : 1);

        return $value;
    }

    private function location(SocialAccount $account): ?string
    {
        $location = GoogleBusinessResourceName::connectedLocation($account->meta);

        if ($location === null) {
            return null;
        }

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        return $location['name'];
    }

    /**
     * @return list<array{keyword: string, value: int, estimated: bool}>|false
     */
    private function fetchSearchKeywordsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array|false
    {
        $locationName = $this->location($account);

        if ($locationName === null) {
            return false;
        }

        $keywords = [];
        $pageToken = null;

        do {
            $response = $this->socialHttp()->withToken($account->access_token)
                ->get("{$this->baseUrl}/{$locationName}/searchkeywords/impressions/monthly", array_filter([
                    'monthlyRange.start_month.year' => (int) $since->format('Y'),
                    'monthlyRange.start_month.month' => (int) $since->format('n'),
                    'monthlyRange.end_month.year' => (int) $until->format('Y'),
                    'monthlyRange.end_month.month' => (int) $until->format('n'),
                    'pageSize' => 100,
                    'pageToken' => $pageToken,
                ]));

            if ($response->failed()) {
                Log::warning('Google Business Profile search keywords fetch failed', [
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                return false;
            }

            $payload = $response->json();

            foreach (data_get($payload, 'searchKeywordsCounts', []) as $entry) {
                $threshold = data_get($entry, 'insightsValue.threshold');

                $keywords[] = [
                    'keyword' => (string) data_get($entry, 'searchKeyword'),
                    // Google withholds the count for low-volume terms and sends
                    // the floor instead. The estimated flag is what keeps that
                    // floor from being shown as a real count.
                    'value' => (int) (data_get($entry, 'insightsValue.value') ?? $threshold ?? 0),
                    'estimated' => $threshold !== null,
                ];
            }

            $pageToken = data_get($payload, 'nextPageToken');
        } while (filled($pageToken));

        return $keywords;
    }

    /**
     * @return list<array{label: string, value: int}>|false
     */
    private function fetchMetricsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array|false
    {
        $locationName = $this->location($account);

        if ($locationName === null) {
            return false;
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->get("{$this->baseUrl}/{$locationName}:fetchMultiDailyMetricsTimeSeries?{$this->buildQuery($since, $until)}");

        if ($response->failed()) {
            Log::warning('Google Business Profile analytics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return false;
        }

        $labels = self::METRICS + self::CONDITIONAL_METRICS;
        $totals = array_fill_keys(array_keys($labels), 0);

        foreach (data_get($response->json(), 'multiDailyMetricTimeSeries.0.dailyMetricTimeSeries', []) as $entry) {
            $metric = data_get($entry, 'dailyMetric');

            if (! array_key_exists($metric, $totals)) {
                continue;
            }

            $totals[$metric] = collect(data_get($entry, 'timeSeries.datedValues', []))
                ->sum(fn ($value) => (int) data_get($value, 'value', 0));
        }

        $metrics = [];

        foreach ($labels as $metric => $labelKey) {
            $value = $totals[$metric];

            if (isset(self::CONDITIONAL_METRICS[$metric]) && $value === 0) {
                continue;
            }

            $metrics[] = ['label' => __($labelKey), 'value' => $value];
        }

        return $metrics;
    }

    /**
     * Google expects `dailyMetrics` as repeated scalar params, which
     * `http_build_query` (and therefore the HTTP client's array query support)
     * would encode as `dailyMetrics[0]=...` instead.
     */
    private function buildQuery(CarbonInterface $since, CarbonInterface $until): string
    {
        $metrics = implode('&', array_map(
            fn (string $metric): string => 'dailyMetrics='.urlencode($metric),
            array_keys(self::METRICS + self::CONDITIONAL_METRICS),
        ));

        $range = http_build_query([
            'dailyRange.start_date.year' => $since->format('Y'),
            'dailyRange.start_date.month' => $since->format('n'),
            'dailyRange.start_date.day' => $since->format('j'),
            'dailyRange.end_date.year' => $until->format('Y'),
            'dailyRange.end_date.month' => $until->format('n'),
            'dailyRange.end_date.day' => $until->format('j'),
        ]);

        return "{$metrics}&{$range}";
    }
}

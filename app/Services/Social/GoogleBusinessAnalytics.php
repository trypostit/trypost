<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GoogleBusinessAnalytics
{
    use HasSocialHttpClient;

    /** @var array<string, string> Google metric enum => translation key. */
    /** Metrics every Business Profile reports, whatever the business does. */
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
     * Metrics Google only ever fills for a matching business type — bookings
     * need Reserve with Google, the food pair needs a food listing. A dentist
     * would otherwise stare at three permanent zeros, so these are rendered
     * only when the period actually reported something.
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

        $cacheKey = "analytics:google_business:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}";
        $cacheTtl = app()->isProduction() ? 3600 : 1;

        return Cache::remember($cacheKey, $cacheTtl, fn () => $this->fetchMetricsFromApi($account, $since, $until));
    }

    private function fetchMetricsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $locationName = (string) data_get($account->meta, 'location_name');

        if (blank($locationName)) {
            return [];
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->get("{$this->baseUrl}/{$locationName}:fetchMultiDailyMetricsTimeSeries?{$this->buildQuery($since, $until)}");

        if ($response->failed()) {
            Log::warning('Google Business Profile analytics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $series = data_get($response->json(), 'multiDailyMetricTimeSeries.0.dailyMetricTimeSeries', []);

        $requested = self::METRICS + self::CONDITIONAL_METRICS;
        $totals = collect($requested)->mapWithKeys(fn ($labelKey, $metric) => [$metric => 0])->all();

        foreach ($series as $entry) {
            $metric = data_get($entry, 'dailyMetric');

            if (! array_key_exists($metric, $totals)) {
                continue;
            }

            $values = collect(data_get($entry, 'timeSeries.datedValues', []))
                ->sum(fn ($value) => (int) data_get($value, 'value', 0));

            $totals[$metric] = $values;
        }

        return collect($requested)
            ->reject(fn (string $labelKey, string $metric): bool => isset(self::CONDITIONAL_METRICS[$metric]) && $totals[$metric] === 0)
            ->map(fn (string $labelKey, string $metric) => ['label' => __($labelKey), 'value' => $totals[$metric]])
            ->values()
            ->all();
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

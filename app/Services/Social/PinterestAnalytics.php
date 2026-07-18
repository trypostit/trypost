<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PinterestAnalytics
{
    use HasSocialHttpClient;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.pinterest.api');
    }

    private string $accessToken;

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(7);
        $until ??= now();

        $cacheKey = "analytics:pinterest:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}";
        $cacheTtl = app()->isProduction() ? 3600 : 1;

        return Cache::remember($cacheKey, $cacheTtl, function () use ($account, $since, $until) {
            return $this->fetchMetricsFromApi($account, $since, $until);
        });
    }

    public function fetchPostMetrics(PostPlatform $postPlatform): array
    {
        $account = $postPlatform->socialAccount;

        if (! $account || ! $postPlatform->platform_post_id) {
            return ['unsupported' => true, 'reason' => 'missing_post_id'];
        }

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $start = now()->subDays(90)->format('Y-m-d');
        $end = now()->format('Y-m-d');

        $response = $this->socialHttp()
            ->withToken($account->access_token)
            ->get("{$this->baseUrl}/pins/{$postPlatform->platform_post_id}/analytics", [
                'start_date' => $start,
                'end_date' => $end,
                'metric_types' => 'IMPRESSION,SAVE,PIN_CLICK,OUTBOUND_CLICK,VIDEO_MRC_VIEW',
            ]);

        if ($response->failed()) {
            Log::warning('Pinterest post metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        $data = data_get($response->json(), 'all.summary_metrics', []);

        $rows = [
            'IMPRESSION' => __('analytics.metrics.impressions'),
            'SAVE' => __('analytics.metrics.saves'),
            'PIN_CLICK' => __('analytics.metrics.pin_clicks'),
            'OUTBOUND_CLICK' => __('analytics.metrics.outbound_clicks'),
            'VIDEO_MRC_VIEW' => __('analytics.metrics.video_views'),
        ];

        return collect($rows)
            ->map(fn (string $label, string $key) => [
                'label' => $label,
                'value' => (int) data_get($data, $key, 0),
            ])
            ->values()
            ->all();
    }

    private function fetchMetricsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/user_account/analytics", [
                'start_date' => $since->format('Y-m-d'),
                'end_date' => $until->format('Y-m-d'),
            ]);

        if ($response->failed()) {
            Log::warning('Pinterest analytics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $dailyMetrics = data_get($response->json(), 'all.daily_metrics', []);

        if (empty($dailyMetrics)) {
            return [];
        }

        $totals = [
            'PIN_CLICK_RATE' => 0,
            'IMPRESSION' => 0,
            'PIN_CLICK' => 0,
            'ENGAGEMENT' => 0,
            'SAVE' => 0,
        ];

        $count = 0;

        foreach ($dailyMetrics as $day) {
            $metrics = data_get($day, 'metrics', []);

            if (! isset($metrics['PIN_CLICK_RATE'])) {
                continue;
            }

            $count++;
            $totals['PIN_CLICK_RATE'] += $metrics['PIN_CLICK_RATE'];
            $totals['IMPRESSION'] += $metrics['IMPRESSION'] ?? 0;
            $totals['PIN_CLICK'] += $metrics['PIN_CLICK'] ?? 0;
            $totals['ENGAGEMENT'] += $metrics['ENGAGEMENT'] ?? 0;
            $totals['SAVE'] += $metrics['SAVE'] ?? 0;
        }

        $avgClickRate = $count > 0 ? round($totals['PIN_CLICK_RATE'] / $count, 4) : 0;

        return [
            ['label' => __('analytics.metrics.impressions'), 'value' => $totals['IMPRESSION']],
            ['label' => __('analytics.metrics.pin_clicks'), 'value' => $totals['PIN_CLICK']],
            ['label' => __('analytics.metrics.engagement'), 'value' => $totals['ENGAGEMENT']],
            ['label' => __('analytics.metrics.saves'), 'value' => $totals['SAVE']],
            ['label' => __('analytics.metrics.pin_click_rate'), 'value' => $avgClickRate],
        ];
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->withToken($this->accessToken);
    }
}

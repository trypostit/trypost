<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\PostPlatform\ContentType;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InstagramAnalytics
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(7);
        $until ??= now();

        $cacheKey = "analytics:instagram:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}";
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

        $this->baseUrl = $account->platform->instagramGraphBaseUrl();

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        // Per-post metric set differs by content type. Reels/Stories expose
        // different fields than feed posts. Pick the right set per type.
        $metrics = match ($postPlatform->content_type) {
            ContentType::InstagramReel => 'reach,likes,comments,shares,saved,plays',
            ContentType::InstagramStory => 'reach,impressions,replies',
            default => 'reach,likes,comments,shares,saved,total_interactions',
        };

        $response = $this->socialHttp()
            ->get("{$this->baseUrl}/{$postPlatform->platform_post_id}/insights", [
                'metric' => $metrics,
                'access_token' => $this->accessToken,
            ]);

        if ($response->failed()) {
            Log::warning('Instagram post metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        $data = data_get($response->json(), 'data', []);

        return collect($data)
            ->map(fn (array $item) => [
                'label' => ucfirst(str_replace('_', ' ', data_get($item, 'name', ''))),
                'value' => (int) data_get($item, 'values.0.value', 0),
            ])
            ->values()
            ->all();
    }

    private function fetchMetricsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        $this->baseUrl = $account->platform->instagramGraphBaseUrl();

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $metrics = [];

        $timeSeriesMetrics = $this->fetchTimeSeriesMetrics($account, $since, $until);
        $metrics = array_merge($metrics, $timeSeriesMetrics);

        $totalValueMetrics = $this->fetchTotalValueMetrics($account, $since, $until);
        $metrics = array_merge($metrics, $totalValueMetrics);

        return $metrics;
    }

    private function fetchTimeSeriesMetrics(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/{$account->platform_user_id}/insights", [
                'metric' => 'reach,follower_count',
                'period' => 'day',
                'since' => $since->startOfDay()->unix(),
                'until' => $until->endOfDay()->unix(),
                'access_token' => $this->accessToken,
            ]);

        if ($response->failed()) {
            Log::warning('Instagram insights (time series) fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $data = data_get($response->json(), 'data', []);
        $metrics = [];

        foreach ($data as $metric) {
            $name = data_get($metric, 'name');
            $values = data_get($metric, 'values', []);

            if (empty($values)) {
                continue;
            }

            $total = collect($values)->sum('value');

            $label = match ($name) {
                'reach' => __('analytics.metrics.reach'),
                'follower_count' => __('analytics.metrics.followers'),
                default => ucfirst(str_replace('_', ' ', $name)),
            };

            $metrics[] = ['label' => $label, 'value' => $total];
        }

        return $metrics;
    }

    private function fetchTotalValueMetrics(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/{$account->platform_user_id}/insights", [
                'metric' => 'likes,comments,shares,saves,views,total_interactions',
                'metric_type' => 'total_value',
                'period' => 'day',
                'since' => $since->startOfDay()->unix(),
                'until' => $until->endOfDay()->unix(),
                'access_token' => $this->accessToken,
            ]);

        if ($response->failed()) {
            Log::warning('Instagram insights (total value) fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $data = data_get($response->json(), 'data', []);
        $metrics = [];

        foreach ($data as $metric) {
            $name = data_get($metric, 'name');
            $value = data_get($metric, 'total_value.value', 0);

            $label = match ($name) {
                'total_interactions' => __('analytics.metrics.interactions'),
                'likes' => __('analytics.metrics.likes'),
                'comments' => __('analytics.metrics.comments'),
                'shares' => __('analytics.metrics.shares'),
                'saves' => __('analytics.metrics.saves'),
                'views' => __('analytics.metrics.views'),
                default => ucfirst(str_replace('_', ' ', $name)),
            };

            $metrics[] = ['label' => $label, 'value' => $value];
        }

        return $metrics;
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp();
    }
}

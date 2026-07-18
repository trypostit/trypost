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

class ThreadsAnalytics
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.threads.graph_api');
    }

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(7);
        $until ??= now();

        $cacheKey = "analytics:threads:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}";
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

        $response = $this->socialHttp()
            ->withToken($account->access_token)
            ->get("{$this->baseUrl}/{$postPlatform->platform_post_id}/insights", [
                'metric' => 'views,likes,replies,reposts,quotes',
            ]);

        if ($response->failed()) {
            Log::warning('Threads post metrics fetch failed', [
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
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/{$account->platform_user_id}/threads_insights", [
                'metric' => 'views,likes,replies,reposts,quotes',
                'period' => 'day',
                'since' => $since->startOfDay()->unix(),
                'until' => $until->endOfDay()->unix(),
                'access_token' => $this->accessToken,
            ]);

        if ($response->failed()) {
            Log::warning('Threads insights fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $data = data_get($response->json(), 'data', []);
        $metrics = [];

        foreach ($data as $metric) {
            $name = data_get($metric, 'name');

            // Some metrics return total_value, others return values array
            $totalValue = data_get($metric, 'total_value.value');
            if ($totalValue !== null) {
                $value = $totalValue;
            } else {
                $values = data_get($metric, 'values', []);
                $value = collect($values)->sum('value');
            }

            $label = match ($name) {
                'views' => __('analytics.metrics.views'),
                'likes' => __('analytics.metrics.likes'),
                'replies' => __('analytics.metrics.replies'),
                'reposts' => __('analytics.metrics.reposts'),
                'quotes' => __('analytics.metrics.quotes'),
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

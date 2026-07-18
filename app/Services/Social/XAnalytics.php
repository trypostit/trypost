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

class XAnalytics
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.x.api');
    }

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(7);
        $until ??= now();

        // X API max lookback is 100 days
        $daysDiff = $since->diffInDays($until);
        if ($daysDiff > 100) {
            $since = now()->subDays(100);
        }

        $cacheKey = "analytics:x:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}";
        $cacheTtl = app()->isProduction() ? 3600 : 1;

        return Cache::remember($cacheKey, $cacheTtl, function () use ($account, $since, $until) {
            return $this->fetchMetricsFromApi($account, $since, $until);
        });
    }

    private function fetchMetricsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        // Fetch recent tweets in the period
        $tweetIds = $this->fetchTweetIds($account, $since, $until);

        if (empty($tweetIds)) {
            return [];
        }

        // Fetch public_metrics for those tweets
        return $this->fetchTweetMetrics($tweetIds);
    }

    private function fetchTweetIds(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        $ids = [];
        $paginationToken = null;

        for ($i = 0; $i < 5; $i++) {
            $params = [
                'start_time' => $since->toIso8601ZuluString(),
                'end_time' => $until->toIso8601ZuluString(),
                'max_results' => 100,
            ];

            if ($paginationToken) {
                $params['pagination_token'] = $paginationToken;
            }

            $response = $this->getHttpClient()
                ->get("{$this->baseUrl}/users/{$account->platform_user_id}/tweets", $params);

            if ($response->failed()) {
                Log::warning('X tweets list fetch failed', [
                    'body' => $this->redactResponseBody($response->body()),
                ]);
                break;
            }

            $data = $response->json();
            $tweets = data_get($data, 'data', []);

            foreach ($tweets as $tweet) {
                $ids[] = data_get($tweet, 'id');
            }

            $paginationToken = data_get($data, 'meta.next_token');

            if (! $paginationToken) {
                break;
            }
        }

        return $ids;
    }

    private function fetchTweetMetrics(array $tweetIds): array
    {
        $totals = [
            'impression_count' => 0,
            'like_count' => 0,
            'retweet_count' => 0,
            'reply_count' => 0,
            'quote_count' => 0,
            'bookmark_count' => 0,
        ];

        // X API allows max 100 IDs per request
        foreach (array_chunk($tweetIds, 100) as $chunk) {
            $response = $this->getHttpClient()
                ->get("{$this->baseUrl}/tweets", [
                    'ids' => implode(',', $chunk),
                    'tweet.fields' => 'public_metrics',
                ]);

            if ($response->failed()) {
                Log::warning('X tweets metrics fetch failed', [
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                continue;
            }

            $tweets = data_get($response->json(), 'data', []);

            foreach ($tweets as $tweet) {
                $metrics = data_get($tweet, 'public_metrics', []);
                foreach ($totals as $key => &$total) {
                    $total += data_get($metrics, $key, 0);
                }
            }
        }

        return [
            ['label' => __('analytics.metrics.impressions'), 'value' => $totals['impression_count']],
            ['label' => __('analytics.metrics.likes'), 'value' => $totals['like_count']],
            ['label' => __('analytics.metrics.retweets'), 'value' => $totals['retweet_count']],
            ['label' => __('analytics.metrics.replies'), 'value' => $totals['reply_count']],
            ['label' => __('analytics.metrics.quotes'), 'value' => $totals['quote_count']],
            ['label' => __('analytics.metrics.bookmarks'), 'value' => $totals['bookmark_count']],
        ];
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

        $this->accessToken = $account->access_token;

        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/tweets/{$postPlatform->platform_post_id}", [
                'tweet.fields' => 'public_metrics',
            ]);

        if ($response->failed()) {
            Log::warning('X post metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        $metrics = data_get($response->json(), 'data.public_metrics', []);

        return [
            ['label' => __('analytics.metrics.impressions'), 'value' => data_get($metrics, 'impression_count', 0)],
            ['label' => __('analytics.metrics.likes'), 'value' => data_get($metrics, 'like_count', 0)],
            ['label' => __('analytics.metrics.retweets'), 'value' => data_get($metrics, 'retweet_count', 0)],
            ['label' => __('analytics.metrics.replies'), 'value' => data_get($metrics, 'reply_count', 0)],
            ['label' => __('analytics.metrics.quotes'), 'value' => data_get($metrics, 'quote_count', 0)],
            ['label' => __('analytics.metrics.bookmarks'), 'value' => data_get($metrics, 'bookmark_count', 0)],
        ];
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->withToken($this->accessToken);
    }
}

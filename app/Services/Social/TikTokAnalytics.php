<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TikTokAnalytics
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.tiktok.api');
    }

    public function getMetrics(SocialAccount $account): array
    {
        $cacheKey = "analytics:tiktok:{$account->id}";
        $cacheTtl = app()->isProduction() ? 3600 : 1;

        return Cache::remember($cacheKey, $cacheTtl, function () use ($account) {
            return $this->fetchMetricsFromApi($account);
        });
    }

    private function fetchMetricsFromApi(SocialAccount $account): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $metrics = [];

        $userStats = $this->fetchUserStats();
        $metrics = array_merge($metrics, $userStats);

        $videoMetrics = $this->fetchVideoMetrics();
        $metrics = array_merge($metrics, $videoMetrics);

        return $metrics;
    }

    private function fetchUserStats(): array
    {
        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/user/info/", [
                'fields' => 'follower_count,following_count,likes_count,video_count',
            ]);

        if ($response->failed()) {
            Log::warning('TikTok user stats fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $user = data_get($response->json(), 'data.user', []);

        $metrics = [];

        if (($value = data_get($user, 'follower_count')) !== null) {
            $metrics[] = ['label' => __('analytics.metrics.followers'), 'value' => $value];
        }

        if (($value = data_get($user, 'following_count')) !== null) {
            $metrics[] = ['label' => __('analytics.metrics.following'), 'value' => $value];
        }

        if (($value = data_get($user, 'likes_count')) !== null) {
            $metrics[] = ['label' => __('analytics.metrics.total_likes'), 'value' => $value];
        }

        if (($value = data_get($user, 'video_count')) !== null) {
            $metrics[] = ['label' => __('analytics.metrics.videos'), 'value' => $value];
        }

        return $metrics;
    }

    private function fetchVideoMetrics(): array
    {
        $videoListResponse = $this->getHttpClient()
            ->post("{$this->baseUrl}/video/list/?fields=id", [
                'max_count' => 20,
            ]);

        if ($videoListResponse->failed()) {
            Log::warning('TikTok video list fetch failed', [
                'body' => $this->redactResponseBody($videoListResponse->body()),
            ]);

            return [];
        }

        $videos = data_get($videoListResponse->json(), 'data.videos', []);

        if (empty($videos)) {
            return [];
        }

        $videoIds = array_map(fn ($v) => $v['id'], $videos);

        $queryResponse = $this->getHttpClient()
            ->post("{$this->baseUrl}/video/query/?fields=id,like_count,comment_count,share_count,view_count", [
                'filters' => ['video_ids' => $videoIds],
            ]);

        if ($queryResponse->failed()) {
            Log::warning('TikTok video query failed', [
                'body' => $this->redactResponseBody($queryResponse->body()),
            ]);

            return [];
        }

        $videoDetails = data_get($queryResponse->json(), 'data.videos', []);

        if (empty($videoDetails)) {
            return [];
        }

        $totalViews = 0;
        $totalLikes = 0;
        $totalComments = 0;
        $totalShares = 0;

        foreach ($videoDetails as $video) {
            $totalViews += data_get($video, 'view_count', 0);
            $totalLikes += data_get($video, 'like_count', 0);
            $totalComments += data_get($video, 'comment_count', 0);
            $totalShares += data_get($video, 'share_count', 0);
        }

        return [
            ['label' => __('analytics.metrics.views'), 'value' => $totalViews],
            ['label' => __('analytics.metrics.recent_likes'), 'value' => $totalLikes],
            ['label' => __('analytics.metrics.recent_comments'), 'value' => $totalComments],
            ['label' => __('analytics.metrics.recent_shares'), 'value' => $totalShares],
        ];
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->asJson()->withToken($this->accessToken);
    }
}

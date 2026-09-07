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

class FacebookAnalytics
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.facebook.graph_api');
    }

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(7);
        $until ??= now();

        $cacheKey = "analytics:facebook:{$account->id}:{$since->format('Y-m-d')}:{$until->format('Y-m-d')}";
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

        if ($postPlatform->content_type === ContentType::FacebookReel) {
            return $this->fetchReelMetrics($postPlatform, $account);
        }

        $response = $this->socialHttp()
            ->get("{$this->baseUrl}/{$postPlatform->platform_post_id}/insights", [
                'metric' => 'post_total_media_view_unique,post_media_view,post_reactions_like_total,post_clicks',
                'access_token' => $account->access_token,
            ]);

        if ($response->failed()) {
            Log::warning('Facebook post metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        $labels = [
            'post_total_media_view_unique' => 'Reach',
            'post_media_view' => 'Views',
            'post_reactions_like_total' => 'Likes',
            'post_clicks' => 'Clicks',
        ];

        $insights = data_get($response->json(), 'data', []);

        return collect($insights)
            ->map(fn (array $item) => [
                'label' => $labels[data_get($item, 'name', '')] ?? ucfirst(str_replace('_', ' ', data_get($item, 'name', ''))),
                'value' => (int) data_get($item, 'values.0.value', 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Facebook Reels have no /insights edge - their metrics live on /video_insights.
     * Called without a `metric` parameter, Graph returns the default set (including
     * blue_reels_play_count, post_impressions_unique, post_video_avg_time_watched).
     * Those are mapped to readable labels; dictionary values (reactions per type) are summed.
     */
    private function fetchReelMetrics(PostPlatform $postPlatform, SocialAccount $account): array
    {
        $response = $this->socialHttp()
            ->get("{$this->baseUrl}/{$postPlatform->platform_post_id}/video_insights", [
                'access_token' => $account->access_token,
            ]);

        if ($response->failed()) {
            Log::warning('Facebook reel metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        $labels = [
            'post_impressions_unique' => 'Reach',
            'blue_reels_play_count' => 'Plays',
            'fb_reels_total_plays' => 'Total plays',
            'fb_reels_replay_count' => 'Replays',
            'post_video_likes_by_reaction_type' => 'Likes',
            'post_video_avg_time_watched' => 'Avg watch time ms',
            'post_video_view_time' => 'View time ms',
            'post_video_followers' => 'Follows',
        ];

        $insights = data_get($response->json(), 'data', []);

        return collect($insights)
            ->filter(fn (array $item) => isset($labels[data_get($item, 'name', '')]))
            ->map(function (array $item) use ($labels) {
                $value = data_get($item, 'values.0.value', 0);

                if (! is_numeric($value)) {
                    $value = array_sum(array_map('intval', (array) $value));
                }

                return [
                    'label' => $labels[data_get($item, 'name', '')],
                    'value' => (int) $value,
                ];
            })
            ->values()
            ->all();
    }

    private function fetchMetricsFromApi(SocialAccount $account, CarbonInterface $since, CarbonInterface $until): array
    {
        $this->accessToken = $account->access_token;

        $response = $this->getHttpClient()
            ->get("{$this->baseUrl}/{$account->platform_user_id}/insights", [
                'metric' => 'page_total_media_view_unique,post_total_media_view_unique,page_post_engagements,page_daily_follows,page_media_view',
                'period' => 'day',
                'since' => $since->startOfDay()->unix(),
                'until' => $until->endOfDay()->unix(),
                'access_token' => $this->accessToken,
            ]);

        if ($response->failed()) {
            Log::warning('Facebook page insights fetch failed', [
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
                'page_total_media_view_unique' => __('analytics.metrics.page_reach'),
                'post_total_media_view_unique' => __('analytics.metrics.posts_reach'),
                'page_post_engagements' => __('analytics.metrics.posts_engagement'),
                'page_daily_follows' => __('analytics.metrics.page_followers'),
                'page_media_view' => __('analytics.metrics.page_views'),
                default => ucfirst(str_replace('_', ' ', $name)),
            };

            $metrics[] = ['label' => $label, 'value' => $total];
        }

        return $metrics;
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp();
    }
}

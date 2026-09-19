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

    /**
     * @return array<int, array{label: string, value: int}>|array{unsupported: true, reason: string}
     */
    public function fetchPostMetrics(PostPlatform $postPlatform): array
    {
        $account = $postPlatform->socialAccount;

        if (! $account || ! $postPlatform->platform_post_id) {
            return ['unsupported' => true, 'reason' => 'missing_post_id'];
        }

        [$edge, $metrics] = $this->postMetricsFor($postPlatform);

        $response = $this->socialHttp()
            ->get("{$this->baseUrl}/{$postPlatform->platform_post_id}/{$edge}", [
                'metric' => implode(',', array_keys($metrics)),
                'period' => 'lifetime',
                'access_token' => $account->access_token,
            ]);

        if ($response->failed()) {
            Log::warning('Facebook post metrics fetch failed', [
                'content_type' => $postPlatform->content_type?->value,
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        return collect(data_get($response->json(), 'data', []))
            ->filter(fn (array $item): bool => isset($metrics[data_get($item, 'name')]))
            ->map(fn (array $item): array => [
                'label' => __($metrics[data_get($item, 'name')]),
                'value' => $this->metricValue(data_get($item, 'values.0.value')),
            ])
            ->values()
            ->all();
    }

    /**
     * The stored id tells us which Graph node we are looking at, and each node
     * answers a different insights call:
     *
     * - A Story is a story post; only the `story` metric family is valid.
     * - A feed post (text, photo, carousel) is stored as `{page_id}_{post_id}`
     *   and has the `/insights` edge with `post_*` metrics.
     * - A bare id is a video node: Reels and timeline videos both come back from
     *   Meta as the video's own id. The video node has no `/insights` edge at
     *   all; its numbers live on `/video_insights`, and Meta reports them with
     *   the Reels metric names for any short video.
     *
     * Asking the wrong node is a `#100` rejection, not an empty result. The
     * `post_impressions*` family is deprecated above Graph API v25, so feed
     * posts read the `media_view` replacements. Every call pins
     * `period=lifetime`: without it Meta returns some post metrics twice, once
     * per period, and the card would show the same label two times.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function postMetricsFor(PostPlatform $postPlatform): array
    {
        if ($postPlatform->content_type === ContentType::FacebookStory) {
            return ['insights', [
                'page_story_impressions_by_story_id' => 'analytics.metrics.impressions',
                'page_story_impressions_by_story_id_unique' => 'analytics.metrics.reach',
                'story_interaction' => 'analytics.metrics.interactions',
                'pages_fb_story_thread_lightweight_reactions' => 'analytics.metrics.reactions',
                'pages_fb_story_replies' => 'analytics.metrics.replies',
                'pages_fb_story_shares' => 'analytics.metrics.shares',
            ]];
        }

        if (str_contains((string) $postPlatform->platform_post_id, '_')) {
            return ['insights', [
                'post_media_view' => 'analytics.metrics.impressions',
                'post_total_media_view_unique' => 'analytics.metrics.reach',
                'post_reactions_like_total' => 'analytics.metrics.likes',
                'post_clicks' => 'analytics.metrics.clicks',
            ]];
        }

        return ['video_insights', [
            'fb_reels_total_plays' => 'analytics.metrics.video_views',
            'post_video_likes_by_reaction_type' => 'analytics.metrics.reactions',
            'post_video_social_actions' => 'analytics.metrics.interactions',
        ]];
    }

    /**
     * Most metrics are a plain count; the `*_by_reaction_type` and
     * `social_actions` metrics return one count per type and are reported as
     * their sum. An empty breakdown arrives as `[]`.
     */
    private function metricValue(mixed $value): int
    {
        if (is_array($value)) {
            return (int) collect($value)->sum();
        }

        return (int) $value;
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

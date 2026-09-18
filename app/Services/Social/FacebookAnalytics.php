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

        [$edge, $metrics] = $this->postMetricsFor($postPlatform->content_type);

        $response = $this->socialHttp()
            ->get("{$this->baseUrl}/{$postPlatform->platform_post_id}/{$edge}", [
                'metric' => implode(',', array_keys($metrics)),
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
            ->map(fn (array $item): array => [
                'label' => __($metrics[data_get($item, 'name')] ?? 'analytics.metrics.'.data_get($item, 'name', '')),
                'value' => $this->metricValue(data_get($item, 'values.0.value')),
            ])
            ->values()
            ->all();
    }

    /**
     * Each Facebook publish type stores a different kind of Graph node, and each
     * node exposes its own insights: a feed post has `/insights` with `post_*`
     * metrics, a Reel is a bare video whose numbers live on `/video_insights`,
     * and a Story only answers to the `story` metric family. Asking a Story or
     * a Reel for `post_impressions` is a `#100` rejection, not an empty result.
     *
     * The `post_impressions*` family is deprecated above Graph API v25, so feed
     * posts read the `media_view` replacements instead.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function postMetricsFor(?ContentType $contentType): array
    {
        return match ($contentType) {
            ContentType::FacebookReel => ['video_insights', [
                'total_video_impressions' => 'analytics.metrics.impressions',
                'total_video_views' => 'analytics.metrics.video_views',
                'total_video_reactions_by_type_total' => 'analytics.metrics.reactions',
            ]],
            ContentType::FacebookStory => ['insights', [
                'page_story_impressions_by_story_id' => 'analytics.metrics.impressions',
                'page_story_impressions_by_story_id_unique' => 'analytics.metrics.reach',
                'story_interaction' => 'analytics.metrics.interactions',
                'pages_fb_story_thread_lightweight_reactions' => 'analytics.metrics.reactions',
                'pages_fb_story_replies' => 'analytics.metrics.replies',
                'pages_fb_story_shares' => 'analytics.metrics.shares',
            ]],
            default => ['insights', [
                'post_media_view' => 'analytics.metrics.impressions',
                'post_total_media_view_unique' => 'analytics.metrics.reach',
                'post_reactions_like_total' => 'analytics.metrics.likes',
                'post_clicks' => 'analytics.metrics.clicks',
            ]],
        };
    }

    /**
     * Most metrics are a plain count; the `*_by_type_total` family returns one
     * count per reaction type and is reported as their sum.
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

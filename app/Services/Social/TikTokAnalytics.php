<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TikTokAnalytics
{
    use HasSocialHttpClient;

    private const string VIDEO_METRIC_FIELDS = 'id,like_count,comment_count,share_count,view_count';

    private const string VIDEO_LIST_FIELDS = 'id,title,create_time,share_url,like_count,comment_count,share_count,view_count';

    private const int VIDEO_LIST_PAGE_SIZE = 20;

    private const int VIDEO_LIST_MAX_PAGES = 5;

    /**
     * @var array<string, string>
     */
    private const array POST_METRICS = [
        'view_count' => 'analytics.metrics.views',
        'like_count' => 'analytics.metrics.likes',
        'comment_count' => 'analytics.metrics.comments',
        'share_count' => 'analytics.metrics.shares',
    ];

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

    /**
     * TikTok has no media insights edge. Per-post numbers live on
     * `POST /v2/video/query/` and require the video's `item_id`, not the
     * Content Posting `publish_id`. A stored `v_pub_*` / `p_pub_*` is resolved
     * via status fetch: after moderation, `publicaly_available_post_id` is the
     * id `video/query` accepts. Private posts never get one.
     *
     * @return array<int, array{label: string, value: int}>|array{unsupported: true, reason: string}
     */
    public function fetchPostMetrics(PostPlatform $postPlatform): array
    {
        $account = $postPlatform->socialAccount;

        if (! $account || ! $postPlatform->platform_post_id) {
            return ['unsupported' => true, 'reason' => 'missing_post_id'];
        }

        $this->prepareAccessToken($account);

        $videoId = $this->videoIdFor($postPlatform);

        if ($videoId === null) {
            return ['unsupported' => true, 'reason' => 'missing_post_id'];
        }

        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/video/query/?fields=".self::VIDEO_METRIC_FIELDS, [
                'filters' => ['video_ids' => [$videoId]],
            ]);

        if ($response->failed()) {
            Log::warning('TikTok post metrics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        $video = collect(data_get($response->json(), 'data.videos', []))
            ->first(fn (mixed $item): bool => (string) data_get($item, 'id') === $videoId);

        if (! is_array($video)) {
            return ['unsupported' => true, 'reason' => 'api_error'];
        }

        return collect(self::POST_METRICS)
            ->map(fn (string $label, string $field): array => [
                'label' => __($label),
                'value' => (int) data_get($video, $field, 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Public posts often stay on a Content Posting `publish_id` because TikTok
     * omits `publicaly_available_post_id` even after PUBLISH_COMPLETE. The video
     * still shows up on `video/list` with the caption we sent — match that and
     * persist the real item id so the show-page link stops pointing at the profile.
     */
    public function findVideoIdByCaption(PostPlatform $postPlatform): ?string
    {
        $account = $postPlatform->socialAccount;

        if (! $account) {
            return null;
        }

        $this->prepareAccessToken($account);

        return $this->matchVideoFromRecentList($postPlatform);
    }

    private function videoIdFor(PostPlatform $postPlatform): ?string
    {
        $stored = (string) $postPlatform->platform_post_id;

        if (ctype_digit($stored)) {
            return $stored;
        }

        return $this->resolveVideoIdFromPublish($postPlatform, $stored)
            ?? $this->persistResolvedVideo($postPlatform, $this->matchVideoFromRecentList($postPlatform));
    }

    private function resolveVideoIdFromPublish(PostPlatform $postPlatform, string $publishId): ?string
    {
        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/post/publish/status/fetch/", [
                'publish_id' => $publishId,
            ]);

        if ($response->failed()) {
            Log::warning('TikTok publish status fetch for metrics failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return $this->persistResolvedVideo($postPlatform, $this->matchVideoFromRecentList($postPlatform));
        }

        $videoId = data_get($response->json(), 'data.publicaly_available_post_id.0');
        $videoId = is_scalar($videoId) ? (string) $videoId : '';

        if ($videoId === '' || ! ctype_digit($videoId)) {
            return $this->persistResolvedVideo($postPlatform, $this->matchVideoFromRecentList($postPlatform));
        }

        return $this->persistResolvedVideo($postPlatform, $videoId);
    }

    private function matchVideoFromRecentList(PostPlatform $postPlatform): ?string
    {
        $postPlatform->loadMissing('post');

        $caption = $this->normalizeCaption(
            (string) ($postPlatform->post?->content ?? '')
        );

        if ($caption === '') {
            return null;
        }

        $cursor = null;

        for ($page = 0; $page < self::VIDEO_LIST_MAX_PAGES; $page++) {
            $payload = ['max_count' => self::VIDEO_LIST_PAGE_SIZE];

            if (is_int($cursor) || (is_string($cursor) && $cursor !== '')) {
                $payload['cursor'] = $cursor;
            }

            $response = $this->getHttpClient()
                ->post("{$this->baseUrl}/video/list/?fields=".self::VIDEO_LIST_FIELDS, $payload);

            if ($response->failed()) {
                Log::warning('TikTok video list match failed', [
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                return null;
            }

            foreach (data_get($response->json(), 'data.videos', []) as $video) {
                if (! is_array($video)) {
                    continue;
                }

                $videoId = is_scalar(data_get($video, 'id')) ? (string) data_get($video, 'id') : '';
                $title = $this->normalizeCaption((string) data_get($video, 'title', ''));

                if ($videoId !== '' && ctype_digit($videoId) && $this->captionsMatch($caption, $title)) {
                    return $videoId;
                }
            }

            if (! data_get($response->json(), 'data.has_more')) {
                return null;
            }

            $cursor = data_get($response->json(), 'data.cursor');
        }

        return null;
    }

    private function persistResolvedVideo(PostPlatform $postPlatform, ?string $videoId): ?string
    {
        if ($videoId === null || $videoId === '' || ! ctype_digit($videoId)) {
            return null;
        }

        $username = $postPlatform->socialAccount?->username;

        $postPlatform->update([
            'platform_post_id' => $videoId,
            'platform_url' => filled($username)
                ? "https://www.tiktok.com/@{$username}/video/{$videoId}"
                : $postPlatform->platform_url,
        ]);

        return $videoId;
    }

    private function captionsMatch(string $posted, string $title): bool
    {
        return $posted === $title
            || str_starts_with($posted, $title)
            || str_starts_with($title, $posted);
    }

    private function normalizeCaption(string $text): string
    {
        return (string) Str::of(app(ContentSanitizer::class)->displayText($text, Platform::TikTok))
            ->squish()
            ->lower();
    }

    private function prepareAccessToken(SocialAccount $account): void
    {
        if ($account->needsProactiveTokenRefresh()) {
            try {
                app(ConnectionVerifier::class)->refreshToken($account);
                $account->refresh();
            } catch (Throwable $e) {
                Log::warning('TikTok token refresh before post metrics failed', [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->accessToken = $account->access_token;
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
            ->post("{$this->baseUrl}/video/query/?fields=".self::VIDEO_METRIC_FIELDS, [
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

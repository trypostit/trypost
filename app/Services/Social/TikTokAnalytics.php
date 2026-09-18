<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
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

    private const string VIDEO_LIST_FIELDS = 'id,title,create_time';

    private const int VIDEO_LIST_PAGE_SIZE = 20;

    private const int VIDEO_LIST_MAX_PAGES = 5;

    /**
     * `published_at` is stamped after TikTok finishes processing, which can trail
     * the video's `create_time` by up to the status-poll window (~1 h). A day of
     * slack keeps our own video inside the scan on slow publishes.
     */
    private const int PUBLISH_CLOCK_SLACK_SECONDS = 86400;

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
     * still shows up on `video/list` with the caption we sent — match that so
     * the show-page link stops pointing at the profile. SELF_ONLY posts never
     * appear on the list, so they are not looked up.
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

        $videoId = $this->publicVideoIdFromStatus($stored) ?? $this->matchVideoFromRecentList($postPlatform);

        if ($videoId === null) {
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

    private function publicVideoIdFromStatus(string $publishId): ?string
    {
        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/post/publish/status/fetch/", [
                'publish_id' => $publishId,
            ]);

        if ($response->failed()) {
            Log::warning('TikTok publish status fetch for metrics failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        return $this->digitsOrNull($response->json('data.publicaly_available_post_id.0'));
    }

    /**
     * `video/list` is sorted by `create_time` desc, so scanning stops at the
     * first video older than the publish — anything past it cannot be ours, and
     * an older repost with the same caption must never be claimed.
     */
    private function matchVideoFromRecentList(PostPlatform $postPlatform): ?string
    {
        if (PrivacyLevel::tryFrom((string) data_get($postPlatform->meta, 'privacy_level')) === PrivacyLevel::SelfOnly) {
            return null;
        }

        $postPlatform->loadMissing('post');

        $caption = $this->normalizeCaption((string) $postPlatform->post?->content);

        if ($caption === '') {
            return null;
        }

        $notBefore = ($postPlatform->published_at ?? now())->getTimestamp() - self::PUBLISH_CLOCK_SLACK_SECONDS;
        $cursor = null;

        for ($page = 0; $page < self::VIDEO_LIST_MAX_PAGES; $page++) {
            $payload = ['max_count' => self::VIDEO_LIST_PAGE_SIZE];

            if (filled($cursor)) {
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

            $data = $response->json('data', []);

            foreach (data_get($data, 'videos', []) as $video) {
                if ((int) data_get($video, 'create_time', 0) < $notBefore) {
                    return null;
                }

                $videoId = $this->digitsOrNull(data_get($video, 'id'));
                $title = $this->normalizeCaption((string) data_get($video, 'title', ''));

                if ($videoId !== null && $this->captionsMatch($caption, $title)) {
                    return $videoId;
                }
            }

            $cursor = data_get($data, 'cursor');

            if (! data_get($data, 'has_more') || blank($cursor)) {
                return null;
            }
        }

        return null;
    }

    /**
     * `video/list` titles may be a truncated form of the caption we posted, so a
     * prefix match in either direction counts. An empty title never matches:
     * `str_starts_with($x, '')` is true and would claim any untitled video.
     */
    private function captionsMatch(string $posted, string $title): bool
    {
        return $title !== ''
            && (str_starts_with($posted, $title) || str_starts_with($title, $posted));
    }

    private function digitsOrNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return ctype_digit($value) ? $value : null;
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

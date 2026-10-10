<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Enums\TikTok\PublishStatus;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\TikTokPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Support\PostPlatformMetaRules;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TikTokPublisher
{
    use HasSocialHttpClient;

    private const int STATUS_RETRY_DELAY_SECONDS = 30;

    private const int STATUS_MAX_RETRIES = 120;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.tiktok.api');
    }

    public function publish(Post $post): array
    {
        $this->validateContentLength($post);

        $content = app(ContentSanitizer::class)->forPost($post);

        $account = $post->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $pendingPublishId = PublishCheckpoint::tiktokPublishId($post->error_context);

        if ($pendingPublishId !== null) {
            return $this->completePublishWithCleanup(
                $post,
                $pendingPublishId,
                PublishCheckpoint::tiktokDerivativePaths($post->error_context),
            );
        }

        $media = $post->mediaItems;

        if ($media->isEmpty()) {
            throw new TikTokPublishException(
                userMessage: 'TikTok requires media (video or photos) to publish.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $firstMedia = $media->first();
        $isVideo = $firstMedia->isVideo();
        $isImage = $firstMedia->isImage();

        if ($isVideo) {
            return $this->publishVideo($post, $firstMedia, $content);
        }

        if ($isImage) {
            return $this->publishPhotos($post, $media, $content);
        }

        throw new TikTokPublishException(
            userMessage: 'TikTok only supports video or image content.',
            category: ErrorCategory::MediaFormat,
        );
    }

    /**
     * The public video id TikTok reports for a published post's `publish_id`.
     * TikTok sends it only once the post clears moderation, which can take hours.
     */
    public function publicVideoId(Post $post): ?string
    {
        $account = $post->socialAccount;

        if (blank($account) || blank($post->platform_post_id)) {
            return null;
        }

        try {
            if ($account->needsProactiveTokenRefresh()) {
                app(ConnectionVerifier::class)->refreshToken($account);
            }
        } catch (TokenExpiredException|PlatformUnavailableException) {
            return null;
        }

        $this->accessToken = $account->access_token;

        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/post/publish/status/fetch/", [
                'publish_id' => $post->platform_post_id,
            ]);

        return $response->successful() ? $this->videoIdFrom((array) $response->json('data', [])) : null;
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->asJson()->withToken($this->accessToken);
    }

    /**
     * Resolve the user-selected privacy_level from meta. TikTok UX Guideline
     * Point 2b forbids any default — the user must pick explicitly. The
     * FormRequest enforces the same rules upstream; this is the safety net for
     * queue/job paths that bypass the request layer.
     */
    private function resolveRequiredPrivacyLevel(Post $post): PrivacyLevel
    {
        $violation = PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, $post->meta);

        if ($violation !== null) {
            throw new TikTokPublishException(
                userMessage: $violation[1],
                category: ErrorCategory::ContentPolicy,
            );
        }

        return PrivacyLevel::from((string) data_get($post->meta, 'privacy_level'));
    }

    /**
     * Build the post_info payload for a VIDEO post. TikTok's video endpoint
     * accepts the caption in the `title` field (capped at 2200 chars by the
     * platform's maxContentLength).
     *
     * @return array<string, mixed>
     */
    private function buildVideoPostInfo(Post $post, ?string $content, ?int $coverOffsetMs = null): array
    {
        $meta = $post->meta ?? [];

        $postInfo = [
            'title' => $content ?? '',
            'privacy_level' => $this->resolveRequiredPrivacyLevel($post)->value,
            'disable_duet' => ! data_get($meta, 'allow_duet', false),
            'disable_comment' => ! data_get($meta, 'allow_comments', false),
            'disable_stitch' => ! data_get($meta, 'allow_stitch', false),
        ];

        if (data_get($meta, 'is_aigc', false)) {
            $postInfo['is_aigc'] = true;
        }

        if (data_get($meta, 'brand_content_toggle', false)) {
            $postInfo['brand_content_toggle'] = true;
        }

        if (data_get($meta, 'brand_organic_toggle', false)) {
            $postInfo['brand_organic_toggle'] = true;
        }

        if ($coverOffsetMs !== null) {
            $postInfo['video_cover_timestamp_ms'] = $coverOffsetMs;
        }

        return $postInfo;
    }

    /**
     * Build the post_info payload for a PHOTO carousel. TikTok's photo endpoint
     * accepts the caption in the `description` field (cap 4000 UTF-16 runes).
     * The `title` field is a separate 90-char headline that we don't expose,
     * so we omit it. Duet/Stitch and is_aigc do not apply to photo posts.
     *
     * @return array<string, mixed>
     */
    private function buildPhotoPostInfo(Post $post, ?string $content): array
    {
        $meta = $post->meta ?? [];

        $postInfo = [
            'description' => $content ?? '',
            'privacy_level' => $this->resolveRequiredPrivacyLevel($post)->value,
            'disable_comment' => ! data_get($meta, 'allow_comments', false),
        ];

        if (data_get($meta, 'brand_content_toggle', false)) {
            $postInfo['brand_content_toggle'] = true;
        }

        if (data_get($meta, 'brand_organic_toggle', false)) {
            $postInfo['brand_organic_toggle'] = true;
        }

        return $postInfo;
    }

    private function publishVideo(Post $post, $media, ?string $content): array
    {
        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/post/publish/video/init/", [
                'post_info' => $this->buildVideoPostInfo($post, $content, $media->coverOffsetMs()),
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $media->url,
                ],
            ]);

        if ($response->failed()) {
            Log::error('TikTok video publish failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();

        $publishId = $this->requirePublishId(data_get($data, 'data.publish_id'));

        $this->rememberPublishId($post, $publishId);

        return $this->completePublish($post, $publishId);
    }

    private function publishPhotos(Post $post, $mediaCollection, ?string $content): array
    {
        $images = $mediaCollection->filter(fn ($m) => $m->isImage())->values();

        if ($images->isEmpty()) {
            throw new TikTokPublishException(
                userMessage: 'No valid images found for TikTok photo post',
                category: ErrorCategory::MediaFormat,
            );
        }

        $derivatives = [];

        try {
            $photoUrls = [];

            foreach ($images as $image) {
                [$url, $derivativePath] = $this->resolvePhotoUrl($image);
                $photoUrls[] = $url;

                if ($derivativePath !== null) {
                    $derivatives[] = $derivativePath;
                }
            }

            $postInfo = $this->buildPhotoPostInfo($post, $content);

            // Auto add music is only for photos.
            $meta = $post->meta ?? [];
            if (data_get($meta, 'auto_add_music', false)) {
                $postInfo['auto_add_music'] = true;
            }

            $response = $this->getHttpClient()
                ->post("{$this->baseUrl}/post/publish/content/init/", [
                    'post_info' => $postInfo,
                    'source_info' => [
                        'source' => 'PULL_FROM_URL',
                        'photo_cover_index' => 0,
                        'photo_images' => $photoUrls,
                    ],
                    'post_mode' => 'DIRECT_POST',
                    'media_type' => 'PHOTO',
                ]);

            if ($response->failed()) {
                Log::error('TikTok photo publish failed', [
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);
                $this->handleApiError($response);
            }

            $publishId = $this->requirePublishId(data_get($response->json(), 'data.publish_id'));

            $this->rememberPublishId($post, $publishId, $derivatives);
        } catch (Throwable $e) {
            app(TikTokPhotoDerivativeCleaner::class)->cleanupPaths($derivatives);

            throw $e;
        }

        return $this->completePublishWithCleanup($post, $publishId, $derivatives);
    }

    /**
     * Resolve the URL TikTok will PULL_FROM_URL for a single photo. TikTok rejects
     * images wider than 1080px with picture_size_check_failed, and because the
     * platform fetches the bytes from us we cannot optimize them in-flight like
     * the upload-based publishers do. So an oversized image is rendered to a
     * spec-compliant JPEG derivative hosted on our public disk and that URL is
     * handed to TikTok instead. Images already within spec pass through untouched.
     *
     * @return array{0: string, 1: string|null} the URL to publish, and the
     *                                          storage path of any derivative
     *                                          created (null when passed through)
     */
    private function resolvePhotoUrl(MediaItem $image): array
    {
        $maxWidth = app(MediaOptimizer::class)->maxWidthForPlatform(Platform::TikTok);
        $width = $image->width();

        if ($maxWidth !== null && $width !== null && $width <= $maxWidth) {
            return [$image->url, null];
        }

        return $this->renderCompliantPhoto($image);
    }

    /**
     * Download the image, resize it to TikTok's spec, and host the copy on the
     * public disk so TikTok can pull it.
     *
     * @return array{0: string, 1: string} the derivative's public URL and its
     *                                     storage path (for later cleanup)
     */
    private function renderCompliantPhoto(MediaItem $image): array
    {
        $tempInput = tempnam(sys_get_temp_dir(), 'tiktok_photo_');

        try {
            $download = Http::sink($tempInput)->timeout(120)->get($image->url);

            if ($download->failed()) {
                throw new TikTokPublishException(
                    userMessage: 'Failed to download image for TikTok resizing',
                    category: ErrorCategory::ServerError,
                );
            }

            return $this->hostResizedPhoto($tempInput);
        } finally {
            @unlink($tempInput);
        }
    }

    /**
     * Resize the downloaded file to TikTok's spec and host the copy on the public
     * disk. Decoder/storage failures are surfaced as a categorized publish
     * exception instead of leaking as an uncategorized error.
     *
     * @return array{0: string, 1: string} the derivative's public URL and storage path
     */
    private function hostResizedPhoto(string $tempInput): array
    {
        try {
            $optimized = app(MediaOptimizer::class)->optimizeImage($tempInput, Platform::TikTok);

            try {
                $path = TikTokPhotoDerivativeCleaner::DIRECTORY.'/'.Str::uuid()->toString().'.jpg';
                Storage::put($path, file_get_contents($optimized));
            } finally {
                @unlink($optimized);
            }

            return [Storage::url($path), $path];
        } catch (Throwable $e) {
            Log::error('TikTok photo resize/host failed', [
                'exception' => $e->getMessage(),
            ]);

            throw new TikTokPublishException(
                userMessage: 'Failed to prepare image for TikTok.',
                category: ErrorCategory::ServerError,
            );
        }
    }

    private function waitForPublishStatus(string $publishId): array
    {
        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/post/publish/status/fetch/", [
                'publish_id' => $publishId,
            ]);

        if ($response->failed()) {
            if ($response->status() !== 429 && ! $response->serverError()) {
                $this->handleApiError($response);
            }

            throw $this->pendingPublishException($publishId, $response->status());
        }

        $data = $response->json();
        $statusValue = (string) data_get($data, 'data.status', '');
        $status = PublishStatus::tryFrom($statusValue);

        return match ($status) {
            PublishStatus::PublishComplete => data_get($data, 'data', []),
            PublishStatus::Failed => throw TikTokPublishException::fromFailReason(
                (string) data_get($data, 'data.fail_reason', 'Unknown error'),
                json_encode($data),
            ),
            default => throw $this->pendingPublishException(
                $publishId,
                status: $statusValue !== '' ? $statusValue : null,
            ),
        };
    }

    private function requirePublishId(mixed $publishId): string
    {
        $resolved = is_string($publishId) && $publishId !== '' ? $publishId : null;

        if ($resolved === null) {
            throw new TikTokPublishException(
                userMessage: 'TikTok did not return a publish_id',
                category: ErrorCategory::ServerError,
            );
        }

        return $resolved;
    }

    /**
     * Persist the publish_id before status polling so a crash after /init/
     * can resume without creating a second publish.
     *
     * @param  list<string>  $derivatives
     */
    private function rememberPublishId(Post $post, string $publishId, array $derivatives = []): void
    {
        $context = [
            ...($post->error_context ?? []),
            PublishCheckpoint::TIKTOK_PUBLISH_ID => $publishId,
        ];

        if ($derivatives !== []) {
            $context[PublishCheckpoint::TIKTOK_DERIVATIVE_PATHS] = $derivatives;
        }

        $post->writePublication([
            'error_context' => $context,
        ]);
    }

    private function pendingPublishException(string $publishId, ?int $httpStatus = null, ?string $status = null): PlatformUnavailableException
    {
        $context = [PublishCheckpoint::TIKTOK_PUBLISH_ID => $publishId];

        if (is_string($status) && $status !== '') {
            $context[PublishCheckpoint::TIKTOK_STATUS] = $status;
        }

        return new PlatformUnavailableException(
            message: "TikTok is still processing publish_id {$publishId}",
            httpStatus: $httpStatus,
            context: $context,
            retryDelaySeconds: self::STATUS_RETRY_DELAY_SECONDS,
            maxRetries: self::STATUS_MAX_RETRIES,
        );
    }

    /**
     * Finish an in-flight publish and prune hosted photos only when TikTok
     * confirmed the attempt is dead, or when it completed. Resumable
     * interruptions (still processing, expired token, unexpected crash)
     * must keep the files so a later status poll can still PULL_FROM_URL.
     *
     * @param  array<array-key, mixed>  $derivatives
     * @return array<string, mixed>
     */
    private function completePublishWithCleanup(Post $post, string $publishId, array $derivatives): array
    {
        $retainDerivatives = true;

        try {
            $result = $this->completePublish($post, $publishId);
            $retainDerivatives = false;

            return $result;
        } catch (PlatformUnavailableException $e) {
            $e->context[PublishCheckpoint::TIKTOK_DERIVATIVE_PATHS] = $derivatives;

            throw $e;
        } catch (TikTokPublishException $e) {
            $retainDerivatives = false;

            throw $e;
        } finally {
            if (! $retainDerivatives) {
                app(TikTokPhotoDerivativeCleaner::class)->cleanupPaths($derivatives);
            }
        }
    }

    private function completePublish(Post $post, string $publishId): array
    {
        $statusData = $this->waitForPublishStatus($publishId);
        $postId = $this->videoIdFrom($statusData);

        return [
            'id' => $postId ?? $publishId,
            'url' => self::postUrl($post->socialAccount, $postId),
        ];
    }

    /**
     * @param  array<string, mixed>  $statusData
     */
    private function videoIdFrom(array $statusData): ?string
    {
        return transform(data_get($statusData, 'publicaly_available_post_id.0'), fn (mixed $id): string => (string) $id);
    }

    public static function postUrl(SocialAccount $account, ?string $postId = null): ?string
    {
        $username = $account->username;

        if ($username && $postId) {
            return "https://www.tiktok.com/@{$username}/video/{$postId}";
        }

        if ($username) {
            return "https://www.tiktok.com/@{$username}";
        }

        return null;
    }

    private function handleApiError(Response $response): never
    {
        throw TikTokPublishException::fromApiResponse($response);
    }
}

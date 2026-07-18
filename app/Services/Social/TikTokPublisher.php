<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\DataTransferObjects\MediaItem;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\TikTokPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
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

    private const PHOTO_DERIVATIVE_DIRECTORY = 'social-tiktok-photos';

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.tiktok.api');
    }

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $account = $postPlatform->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $media = $postPlatform->post->mediaItems;

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
            return $this->publishVideo($postPlatform, $firstMedia, $content);
        }

        if ($isImage) {
            return $this->publishPhotos($postPlatform, $media, $content);
        }

        throw new TikTokPublishException(
            userMessage: 'TikTok only supports video or image content.',
            category: ErrorCategory::MediaFormat,
        );
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->asJson()->withToken($this->accessToken);
    }

    /**
     * Resolve the user-selected privacy_level from meta, throwing when missing.
     * TikTok UX Guideline Point 2b forbids any default — the user must pick
     * explicitly. The FormRequest validates this upstream; this is the safety
     * net for queue/job paths that bypass the request layer.
     */
    private function resolveRequiredPrivacyLevel(PostPlatform $postPlatform): string
    {
        $privacyLevel = data_get($postPlatform->meta ?? [], 'privacy_level');

        if (blank($privacyLevel)) {
            throw new TikTokPublishException(
                userMessage: 'TikTok privacy level is required. Please open the post and pick a visibility option.',
                category: ErrorCategory::ContentPolicy,
            );
        }

        return (string) $privacyLevel;
    }

    /**
     * Build the post_info payload for a VIDEO post. TikTok's video endpoint
     * accepts the caption in the `title` field (capped at 2200 chars by the
     * platform's maxContentLength).
     *
     * @return array<string, mixed>
     */
    private function buildVideoPostInfo(PostPlatform $postPlatform, ?string $content): array
    {
        $meta = $postPlatform->meta ?? [];

        $postInfo = [
            'title' => $content ?? '',
            'privacy_level' => $this->resolveRequiredPrivacyLevel($postPlatform),
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
    private function buildPhotoPostInfo(PostPlatform $postPlatform, ?string $content): array
    {
        $meta = $postPlatform->meta ?? [];

        $postInfo = [
            'description' => $content ?? '',
            'privacy_level' => $this->resolveRequiredPrivacyLevel($postPlatform),
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

    private function publishVideo(PostPlatform $postPlatform, $media, ?string $content): array
    {
        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/post/publish/video/init/", [
                'post_info' => $this->buildVideoPostInfo($postPlatform, $content),
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

        $publishId = data_get($data, 'data.publish_id');

        if (! $publishId) {
            throw new TikTokPublishException(
                userMessage: 'TikTok did not return a publish_id',
                category: ErrorCategory::ServerError,
            );
        }

        // Wait for processing and get final status
        $statusData = $this->waitForPublishStatus($publishId);
        $postId = data_get($statusData, 'publicaly_available_post_id.0');

        return [
            'id' => $postId ?? $publishId,
            'url' => $this->buildTikTokUrl($postPlatform->socialAccount, $postId),
        ];
    }

    private function publishPhotos(PostPlatform $postPlatform, $mediaCollection, ?string $content): array
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

            $postInfo = $this->buildPhotoPostInfo($postPlatform, $content);

            // Auto add music is only for photos.
            $meta = $postPlatform->meta ?? [];
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

            $data = $response->json();

            $publishId = data_get($data, 'data.publish_id');

            if (! $publishId) {
                throw new TikTokPublishException(
                    userMessage: 'TikTok did not return a publish_id',
                    category: ErrorCategory::ServerError,
                );
            }

            // Wait for processing and get final status
            $statusData = $this->waitForPublishStatus($publishId);
            $postId = data_get($statusData, 'publicaly_available_post_id.0');

            return [
                'id' => $postId ?? $publishId,
                'url' => $this->buildTikTokUrl($postPlatform->socialAccount, $postId),
            ];
        } finally {
            $this->pruneDerivatives($derivatives);
        }
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
                $path = self::PHOTO_DERIVATIVE_DIRECTORY.'/'.Str::uuid()->toString().'.jpg';
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

    /**
     * Remove hosted photo derivatives, swallowing storage errors so cleanup can
     * never mask the publish result.
     *
     * @param  list<string>  $paths
     */
    private function pruneDerivatives(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        try {
            Storage::delete($paths);
        } catch (Throwable $e) {
            Log::warning('Failed to prune TikTok photo derivatives', [
                'paths' => $paths,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function waitForPublishStatus(string $publishId, int $maxAttempts = 20): array
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(3);

            $response = $this->getHttpClient()
                ->post("{$this->baseUrl}/post/publish/status/fetch/", [
                    'publish_id' => $publishId,
                ]);

            if ($response->failed()) {
                Log::warning('TikTok status check failed', [
                    'attempt' => $i,
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                continue;
            }

            $data = $response->json();
            $status = data_get($data, 'data.status', 'UNKNOWN');

            if ($status === 'PUBLISH_COMPLETE') {
                return data_get($data, 'data', []);
            }

            if (in_array($status, ['FAILED', 'PUBLISH_FAILED'])) {
                $failReason = data_get($data, 'data.fail_reason', 'Unknown error');
                throw TikTokPublishException::fromFailReason($failReason, json_encode($data));
            }

            // PROCESSING_UPLOAD, PROCESSING_DOWNLOAD, SENDING_TO_USER_INBOX - continue waiting
        }

        Log::warning('TikTok publish status timeout, returning publish_id anyway');

        return ['publish_id' => $publishId];
    }

    private function buildTikTokUrl(SocialAccount $account, ?string $postId = null): ?string
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

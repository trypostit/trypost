<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\CropsImageForAspectRatio;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\Meta\GraphError;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class FacebookPublisher
{
    use CropsImageForAspectRatio;
    use HasSocialHttpClient;

    private const int VIDEO_TRANSFER_TIMEOUT_SECONDS = 600;

    private const int STORY_UPLOAD_POLL_SECONDS = 5;

    private const int STORY_UPLOAD_MAX_POLLS = 60;

    private const int UNREACHABLE_RETRY_DELAY_SECONDS = 60;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.facebook.graph_api');
    }

    /**
     * @return array{id: mixed, url: string}
     */
    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $account = $postPlatform->socialAccount;
        $pageId = $account->platform_user_id;
        $accessToken = $account->access_token;
        $content = $this->sanitizedContent($postPlatform);
        $media = $postPlatform->post->mediaItems;
        $contentType = $postPlatform->content_type;

        return match ($contentType) {
            ContentType::FacebookReel => $this->publishReel($pageId, $accessToken, $content, $this->requireVideo($media->first(), 'Reels')),
            ContentType::FacebookStory => $this->publishStory($pageId, $accessToken, $this->requireVideo($media->first(), 'Stories')),
            ContentType::FacebookPost => $this->publishPost($pageId, $accessToken, $content, $media, data_get($postPlatform->meta, 'aspect_ratio')),
            default => throw new FacebookPublishException(
                userMessage: "Unsupported Facebook content type: {$contentType?->value}",
                category: ErrorCategory::MediaFormat,
            ),
        };
    }

    /**
     * @param  Collection<int, MediaItem>  $media
     * @return array{id: mixed, url: string}
     */
    private function publishPost(string $pageId, string $accessToken, ?string $content, Collection $media, ?string $aspectRatio): array
    {
        if ($media->isEmpty()) {
            return $this->publishTextPost($pageId, $accessToken, $content);
        }

        $firstMedia = $media->first();

        return match (true) {
            $firstMedia->isVideo() => $this->publishVideoPost($pageId, $accessToken, $content, $firstMedia),
            $firstMedia->isImage() && $media->count() === 1 => $this->publishSingleImagePost($pageId, $accessToken, $content, $firstMedia, $aspectRatio),
            $firstMedia->isImage() => $this->publishMultiImagePost($pageId, $accessToken, $content, $media, $aspectRatio),
            default => throw new FacebookPublishException(
                userMessage: 'Unsupported media type for Facebook',
                category: ErrorCategory::MediaFormat,
            ),
        };
    }

    /**
     * @return array{id: mixed, url: string}
     */
    private function publishTextPost(string $pageId, string $accessToken, ?string $content): array
    {
        if (! filled($content)) {
            throw new FacebookPublishException(
                userMessage: 'Facebook text posts require content. Please add text to your post.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $response = $this->postToGraph("{$pageId}/feed", [
            'message' => $content,
            'access_token' => $accessToken,
        ], 'text post');

        return $this->feedPostResult(data_get($response->json(), 'id'));
    }

    /**
     * @return array{id: mixed, url: string}
     */
    private function publishSingleImagePost(string $pageId, string $accessToken, ?string $content, MediaItem $media, ?string $aspectRatio): array
    {
        $response = $this->postToGraph("{$pageId}/photos", [
            'url' => $this->cropImageForAspectRatio($media->url, $aspectRatio),
            'access_token' => $accessToken,
            ...$this->optionalField('message', $content),
            ...$this->altText($media),
        ], 'single image post');

        $data = $response->json();

        return $this->feedPostResult(data_get($data, 'post_id', data_get($data, 'id')));
    }

    /**
     * Every image is uploaded unpublished and then attached to one feed post. An
     * image Facebook rejects is skipped so the rest still goes out; the post only
     * fails when none of them made it.
     *
     * @param  Collection<int, MediaItem>  $media
     * @return array{id: mixed, url: string}
     */
    private function publishMultiImagePost(string $pageId, string $accessToken, ?string $content, Collection $media, ?string $aspectRatio): array
    {
        $photoIds = $media
            ->filter(fn (MediaItem $item): bool => $item->isImage())
            ->map(fn (MediaItem $item): ?string => $this->uploadUnpublishedPhoto($pageId, $accessToken, $item, $aspectRatio))
            ->filter()
            ->values();

        if ($photoIds->isEmpty()) {
            throw new FacebookPublishException(
                userMessage: 'Failed to upload any images to Facebook',
                category: ErrorCategory::ServerError,
            );
        }

        $response = $this->postToGraph("{$pageId}/feed", [
            'access_token' => $accessToken,
            ...$this->optionalField('message', $content),
            ...$photoIds
                ->mapWithKeys(fn (string $photoId, int $index): array => ["attached_media[{$index}]" => json_encode(['media_fbid' => $photoId])])
                ->all(),
        ], 'multi-image post');

        return $this->feedPostResult(data_get($response->json(), 'id'));
    }

    private function uploadUnpublishedPhoto(string $pageId, string $accessToken, MediaItem $media, ?string $aspectRatio): ?string
    {
        $response = $this->reachOrRetry(
            fn (): Response => $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/photos", [
                'url' => $this->cropImageForAspectRatio($media->url, $aspectRatio),
                'published' => 'false',
                'access_token' => $accessToken,
                ...$this->altText($media),
            ]),
            'image upload',
        );

        if ($response->failed()) {
            Log::error('Facebook image upload failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        $photoId = data_get($response->json(), 'id');

        return is_string($photoId) && $photoId !== '' ? $photoId : null;
    }

    /**
     * @return array{id: mixed, url: string}
     */
    private function publishVideoPost(string $pageId, string $accessToken, ?string $content, MediaItem $media): array
    {
        $response = $this->postToGraph("{$pageId}/videos", [
            'file_url' => $media->url,
            'access_token' => $accessToken,
            ...$this->optionalField('description', $content),
        ], 'video post');

        $videoId = data_get($response->json(), 'id');

        return [
            'id' => $videoId,
            'url' => "https://www.facebook.com/{$pageId}/videos/{$videoId}",
        ];
    }

    /**
     * @return array{id: mixed, url: string}
     */
    private function publishReel(string $pageId, string $accessToken, ?string $content, MediaItem $media): array
    {
        [$videoId, $uploadUrl] = $this->startVideoUpload($pageId, $accessToken, 'video_reels');

        $this->uploadVideoBytes($uploadUrl, $accessToken, $media);

        $response = $this->postToGraph("{$pageId}/video_reels", [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'access_token' => $accessToken,
            ...$this->optionalField('description', $content),
        ], 'reel finish');

        $reelId = data_get($response->json(), 'id') ?? $videoId;

        return [
            'id' => $reelId,
            'url' => "https://www.facebook.com/reel/{$reelId}",
        ];
    }

    /**
     * @return array{id: mixed, url: string}
     */
    private function publishStory(string $pageId, string $accessToken, MediaItem $media): array
    {
        [$videoId, $uploadUrl] = $this->startVideoUpload($pageId, $accessToken, 'video_stories');

        $this->uploadVideoFromUrl($uploadUrl, $accessToken, $media);
        $this->waitForStoryUpload($videoId, $accessToken);

        $response = $this->postToGraph("{$pageId}/video_stories", [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'access_token' => $accessToken,
        ], 'story finish');

        $storyId = data_get($response->json(), 'post_id') ?? $videoId;

        return [
            'id' => $storyId,
            'url' => "https://www.facebook.com/stories/{$pageId}/{$storyId}",
        ];
    }

    /**
     * Phase 1 of Meta's resumable video flow, shared by Reels and Stories: the
     * Graph edge opens a session and returns the rupload URL the file must go to.
     *
     * @return array{0: string, 1: string}
     */
    private function startVideoUpload(string $pageId, string $accessToken, string $edge): array
    {
        $response = $this->postToGraph("{$pageId}/{$edge}", [
            'upload_phase' => 'start',
            'access_token' => $accessToken,
        ], "{$edge} start");

        $data = $response->json();
        $videoId = data_get($data, 'video_id');
        $uploadUrl = data_get($data, 'upload_url');

        if (! filled($videoId) || ! is_string($uploadUrl) || ! filled($uploadUrl)) {
            throw new FacebookPublishException(
                userMessage: 'Facebook did not start the video upload. Please try again.',
                category: ErrorCategory::ServerError,
                rawResponse: $response->body(),
            );
        }

        $this->assertRuploadUrl($uploadUrl);

        return [(string) $videoId, $uploadUrl];
    }

    /**
     * The `upload_url` must point at Meta's rupload host. Anything else would
     * send the Page token and our media URL to a third party.
     */
    private function assertRuploadUrl(string $uploadUrl): void
    {
        $parts = parse_url($uploadUrl);

        if (data_get($parts, 'scheme') !== 'https' || data_get($parts, 'host') !== config('trypost.platforms.facebook.rupload_host')) {
            throw new FacebookPublishException(
                userMessage: 'Facebook returned an invalid upload URL.',
                category: ErrorCategory::ServerError,
                rawResponse: $uploadUrl,
            );
        }
    }

    /**
     * Reels still stream the file through the worker. The hosted `file_url`
     * shortcut is only verified on Stories; the Reels session rejected it in
     * the past ("Header Offset not convertable to unsigned long").
     */
    private function uploadVideoBytes(string $uploadUrl, string $accessToken, MediaItem $media): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'fb_reel_');

        if ($tempFile === false) {
            throw $this->videoPreparationException();
        }

        try {
            $download = $this->reachOrRetry(
                fn (): Response => Http::withOptions(['sink' => $tempFile])
                    ->timeout(self::VIDEO_TRANSFER_TIMEOUT_SECONDS)
                    ->get($media->url),
                'media download',
            );

            if ($download->failed()) {
                throw new FacebookPublishException(
                    userMessage: 'Could not download media for Facebook reel.',
                    category: ErrorCategory::ServerError,
                    platformErrorCode: (string) $download->status(),
                );
            }

            $fileSize = filesize($tempFile);
            $stream = $fileSize !== false && $fileSize > 0 ? fopen($tempFile, 'rb') : false;

            if ($stream === false) {
                throw $this->videoPreparationException();
            }

            try {
                $uploadResponse = $this->reachOrRetry(
                    fn (): Response => Http::withHeaders([
                        'Authorization' => "OAuth {$accessToken}",
                        'Offset' => '0',
                        'file_size' => (string) $fileSize,
                    ])
                        ->timeout(self::VIDEO_TRANSFER_TIMEOUT_SECONDS)
                        ->withBody($stream, $media->mime_type ?? 'video/mp4')
                        ->post($uploadUrl),
                    'reel upload',
                );
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($uploadResponse->failed()) {
                $this->handleApiError($uploadResponse);
            }
        } finally {
            if (file_exists($tempFile) && ! unlink($tempFile)) {
                Log::warning('Facebook reel temp file cleanup failed', ['path' => $tempFile]);
            }
        }
    }

    /**
     * Stories hand Meta the CDN URL and let it fetch the file (Page Stories API
     * hosted-file upload). The request is the two headers and no body.
     */
    private function uploadVideoFromUrl(string $uploadUrl, string $accessToken, MediaItem $media): void
    {
        $response = $this->reachOrRetry(
            fn (): Response => $this->socialHttp()
                ->withHeaders([
                    'Authorization' => "OAuth {$accessToken}",
                    'file_url' => $media->url,
                ])
                ->send('POST', $uploadUrl),
            'story upload',
        );

        if ($response->failed()) {
            Log::error('Facebook video story upload failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        if (data_get($response->json(), 'success') !== true) {
            throw new FacebookPublishException(
                userMessage: 'Facebook did not accept the story video. Please try again.',
                category: ErrorCategory::ServerError,
                rawResponse: $response->body(),
            );
        }
    }

    /**
     * With `file_url` Meta fetches the video asynchronously, so the rupload POST
     * returns before the bytes exist on their side. Calling `finish` on that
     * empty session is what produced error 6000; wait until the uploading phase
     * reports complete.
     */
    private function waitForStoryUpload(string $videoId, string $accessToken): void
    {
        for ($attempt = 0; $attempt < self::STORY_UPLOAD_MAX_POLLS; $attempt++) {
            $response = $this->reachOrRetry(
                fn (): Response => $this->socialHttp()->get("{$this->baseUrl}/{$videoId}", [
                    'fields' => 'status',
                    'access_token' => $accessToken,
                ]),
                'story status',
            );

            if ($response->failed()) {
                if (! GraphError::isTransientFailure($response)) {
                    $this->handleApiError($response);
                }

                Log::warning('Facebook story status check failed transiently', [
                    'video_id' => $videoId,
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);
                Sleep::for(self::STORY_UPLOAD_POLL_SECONDS)->seconds();

                continue;
            }

            $status = data_get($response->json(), 'status', []);
            $failure = $this->storyUploadFailure($status);

            if ($failure !== null) {
                throw new FacebookPublishException(
                    userMessage: $failure,
                    category: ErrorCategory::MediaFormat,
                    rawResponse: $response->body(),
                );
            }

            if ($this->storyUploadComplete($status)) {
                return;
            }

            Sleep::for(self::STORY_UPLOAD_POLL_SECONDS)->seconds();
        }

        throw new FacebookPublishException(
            userMessage: 'Facebook took too long to fetch the story video. Please try again.',
            category: ErrorCategory::ServerError,
        );
    }

    /**
     * The user-facing reason the upload failed, or null while it is still healthy.
     * Meta reports a processing failure as an `error` object on the phase, not
     * always as `status: error`, so the message is checked first.
     *
     * @param  array<string, mixed>  $status
     */
    private function storyUploadFailure(array $status): ?string
    {
        $detail = data_get($status, 'processing_phase.error.message')
            ?? data_get($status, 'uploading_phase.error.message');

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        $failed = in_array(data_get($status, 'video_status'), ['error', 'expired'], true)
            || data_get($status, 'uploading_phase.status') === 'error'
            || data_get($status, 'processing_phase.status') === 'error';

        return $failed ? 'Facebook could not process the story video. Please try another file.' : null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function storyUploadComplete(array $status): bool
    {
        return data_get($status, 'uploading_phase.status') === 'complete'
            || in_array(data_get($status, 'video_status'), ['ready', 'upload_complete'], true);
    }

    private function requireVideo(?MediaItem $media, string $format): MediaItem
    {
        if ($media === null || ! $media->isVideo()) {
            throw new FacebookPublishException(
                userMessage: "Facebook {$format} require a video file.",
                category: ErrorCategory::MediaFormat,
            );
        }

        return $media;
    }

    private function sanitizedContent(PostPlatform $postPlatform): ?string
    {
        $content = $postPlatform->post->content;

        return filled($content)
            ? app(ContentSanitizer::class)->sanitize($content, $postPlatform->platform)
            : null;
    }

    /**
     * Graph API expects application/x-www-form-urlencoded (or multipart), not JSON.
     * Sending JSON makes `message` work but silently drops `attached_media[*]` on /feed.
     */
    private function facebookHttp(): PendingRequest
    {
        return $this->socialHttp()->asForm();
    }

    /**
     * POST a form payload to a Graph edge and turn any failure into the typed
     * exception, logging the redacted body under `$label` first.
     *
     * @param  array<string, string>  $payload
     */
    private function postToGraph(string $path, array $payload, string $label): Response
    {
        $response = $this->reachOrRetry(
            fn (): Response => $this->facebookHttp()->post("{$this->baseUrl}/{$path}", $payload),
            $label,
        );

        if ($response->failed()) {
            Log::error("Facebook {$label} failed", [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        return $response;
    }

    /**
     * A connection that never completes (DNS, TCP or TLS timeout) says nothing
     * about the post or the token, so it is rescheduled instead of reported as
     * an unexpected failure. Facebook's Graph and rupload hosts drop connections
     * often enough for this to matter.
     *
     * @param  Closure(): Response  $request
     */
    private function reachOrRetry(Closure $request, string $label): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            throw new PlatformUnavailableException(
                message: "Facebook {$label} unreachable: {$exception->getMessage()}",
                retryDelaySeconds: self::UNREACHABLE_RETRY_DELAY_SECONDS,
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function optionalField(string $key, ?string $value): array
    {
        return filled($value) ? [$key => $value] : [];
    }

    /**
     * @return array<string, string>
     */
    private function altText(MediaItem $media): array
    {
        return $this->optionalField('alt_text_custom', $media->altTextFor(Platform::Facebook));
    }

    /**
     * @return array{id: mixed, url: string}
     */
    private function feedPostResult(mixed $postId): array
    {
        return [
            'id' => $postId,
            'url' => "https://www.facebook.com/{$postId}",
        ];
    }

    private function videoPreparationException(): FacebookPublishException
    {
        return new FacebookPublishException(
            userMessage: 'Could not prepare the Facebook video for upload.',
            category: ErrorCategory::ServerError,
        );
    }

    private function handleApiError(Response $response): never
    {
        throw FacebookPublishException::fromApiResponse($response);
    }

    protected function cropFailureException(string $message): SocialPublishException
    {
        return new FacebookPublishException(
            userMessage: $message,
            category: ErrorCategory::ServerError,
        );
    }
}

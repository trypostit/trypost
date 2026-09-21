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
use App\Support\UrlDetector;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class FacebookPublisher
{
    use CropsImageForAspectRatio;
    use HasSocialHttpClient;

    private const int VIDEO_UPLOAD_POLL_SECONDS = 5;

    private const int VIDEO_UPLOAD_MAX_POLLS = 60;

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

        $link = UrlDetector::firstUrl($content);

        try {
            $response = $this->postTextToFeed($pageId, $accessToken, $content, $link);
        } catch (FacebookPublishException $exception) {
            // Graph error 1609005: Facebook could not scrape the URL. The post
            // would have published as plain text before `link` was sent, so drop
            // the card and try once more instead of failing the whole post.
            if ($link === null || $exception->platformErrorCode !== '1609005') {
                throw $exception;
            }

            Log::warning('Facebook could not scrape the link preview; publishing the text without it');

            $response = $this->postTextToFeed($pageId, $accessToken, $content, null);
        }

        return $this->feedPostResult(data_get($response->json(), 'id'));
    }

    /**
     * A text post carries `link` when the caption contains a URL. Facebook does
     * not unfurl a URL left only in `message`; the Page Feed `link` field is
     * what makes it scrape Open Graph and render the preview.
     */
    private function postTextToFeed(string $pageId, string $accessToken, string $content, ?string $link): Response
    {
        return $this->postToGraph("{$pageId}/feed", [
            'message' => $content,
            'access_token' => $accessToken,
            ...$this->optionalField('link', $link),
        ], 'text post');
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

        return $this->feedPostResult(data_get($data, 'post_id') ?? data_get($data, 'id'));
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

        $photoId = data_get($response->json(), 'id');

        if ($response->failed() || ! is_string($photoId) || $photoId === '') {
            Log::error('Facebook image upload failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        return $photoId;
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
        $videoId = $this->uploadVideo($pageId, $accessToken, 'video_reels', $media);

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
        $videoId = $this->uploadVideo($pageId, $accessToken, 'video_stories', $media);

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
     * Meta's resumable video flow, shared by Reels and Stories. `start` on the
     * Graph edge opens a session and returns a rupload URL; we hand that URL our
     * CDN link and Meta fetches the file itself (the "hosted file" upload), so
     * no video bytes pass through the worker. Because that fetch is
     * asynchronous the caller must not `finish` until the status poll reports
     * the upload complete: finishing an empty session is error 6000.
     *
     * Returns the `video_id` the caller passes to `finish`.
     */
    private function uploadVideo(string $pageId, string $accessToken, string $edge, MediaItem $media): string
    {
        [$videoId, $uploadUrl] = $this->startVideoUpload($pageId, $accessToken, $edge);

        $this->uploadVideoFromUrl($uploadUrl, $accessToken, $media);
        $this->waitForVideoUpload($videoId, $accessToken);

        return $videoId;
    }

    /**
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
        $allowedHost = config('trypost.platforms.facebook.rupload_host');

        if (data_get($parts, 'scheme') !== 'https' || data_get($parts, 'host') !== $allowedHost) {
            throw new FacebookPublishException(
                userMessage: 'Facebook returned an invalid upload URL.',
                category: ErrorCategory::ServerError,
                rawResponse: $uploadUrl,
            );
        }
    }

    /**
     * The request is the two headers and no body; Meta fetches `file_url`.
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
            'video upload',
        );

        if ($response->failed()) {
            Log::error('Facebook video upload failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        if (data_get($response->json(), 'success') !== true) {
            throw new FacebookPublishException(
                userMessage: 'Facebook did not accept the video. Please try again.',
                category: ErrorCategory::ServerError,
                rawResponse: $response->body(),
            );
        }
    }

    private function waitForVideoUpload(string $videoId, string $accessToken): void
    {
        for ($attempt = 1; $attempt <= self::VIDEO_UPLOAD_MAX_POLLS; $attempt++) {
            if ($attempt > 1) {
                Sleep::for(self::VIDEO_UPLOAD_POLL_SECONDS)->seconds();
            }

            $response = $this->reachOrRetry(
                fn (): Response => $this->socialHttp()->get("{$this->baseUrl}/{$videoId}", [
                    'fields' => 'status',
                    'access_token' => $accessToken,
                ]),
                'video status',
            );

            if ($response->failed()) {
                if (! GraphError::isTransientFailure($response)) {
                    $this->handleApiError($response);
                }

                Log::warning('Facebook video status check failed transiently', [
                    'video_id' => $videoId,
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                continue;
            }

            $status = data_get($response->json(), 'status', []);
            $failure = $this->videoUploadFailure($status);

            if ($failure !== null) {
                throw new FacebookPublishException(
                    userMessage: $failure,
                    category: ErrorCategory::MediaFormat,
                    rawResponse: $response->body(),
                );
            }

            if ($this->videoUploadComplete($status)) {
                return;
            }
        }

        throw new FacebookPublishException(
            userMessage: 'Facebook took too long to fetch the video. Please try again.',
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
    private function videoUploadFailure(array $status): ?string
    {
        $detail = data_get($status, 'processing_phase.error.message')
            ?? data_get($status, 'uploading_phase.error.message');

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        $failed = in_array(data_get($status, 'video_status'), ['error', 'expired'], true)
            || data_get($status, 'uploading_phase.status') === 'error'
            || data_get($status, 'processing_phase.status') === 'error';

        return $failed ? 'Facebook could not process the video. Please try another file.' : null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function videoUploadComplete(array $status): bool
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
     * often enough for this to matter. cURL quotes the full URL in its message,
     * query string included, so the message is redacted before it reaches
     * error_context or the logs.
     *
     * @param  Closure(): Response  $request
     */
    private function reachOrRetry(Closure $request, string $label): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            throw new PlatformUnavailableException(
                message: "Facebook {$label} unreachable: ".$this->redactResponseBody($exception->getMessage()),
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

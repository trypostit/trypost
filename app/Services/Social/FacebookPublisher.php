<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
use App\Enums\Facebook\VideoStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\Post;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\Concerns\HostsProcessedImages;
use App\Services\Social\Meta\GraphError;
use App\Support\FacebookLinkPreview;
use App\Support\Social\FacebookCover;
use App\Support\Social\PublishContentValidator;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Uri;

class FacebookPublisher
{
    use HasSocialHttpClient;
    use HostsProcessedImages;

    private const int VIDEO_UPLOAD_POLL_SECONDS = 5;

    private const int VIDEO_UPLOAD_MAX_POLLS = 60;

    private const int UNREACHABLE_RETRY_DELAY_SECONDS = 60;

    private const string VIDEO_PROCESSING_FAILED = 'Facebook could not process the video. Please try another file.';

    /** Meta's limit for a video thumbnail: "Maximum thumbnail file size 10MB". */
    private const int COVER_MAX_BYTES = 10 * 1024 * 1024;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.facebook.graph_api');
    }

    /**
     * @return array{id: mixed, url: string, facebook_cover_warning?: true}
     */
    public function publish(Post $post): array
    {
        PublishContentValidator::validate($post);

        $account = $post->socialAccount;
        $pageId = $account->platform_user_id;
        $accessToken = $account->access_token;
        $content = $this->sanitizedContent($post);
        $media = $post->mediaItems;
        $contentType = $post->content_type;

        return match ($contentType) {
            ContentType::FacebookReel => $this->publishReel($pageId, $accessToken, $content, $this->requireVideo($media->first(), 'Reels')),
            ContentType::FacebookStory => $this->publishStory($pageId, $accessToken, $this->requireMedia($media->first(), 'Stories')),
            ContentType::FacebookPost => $this->publishPost($pageId, $accessToken, $content, $media, $post->attachesLinkPreview()),
            default => throw new FacebookPublishException(
                userMessage: "Unsupported Facebook content type: {$contentType?->value}",
                category: ErrorCategory::MediaFormat,
            ),
        };
    }

    /**
     * @param  Collection<int, MediaItem>  $media
     * @return array{id: mixed, url: string, facebook_cover_warning?: true}
     */
    private function publishPost(string $pageId, string $accessToken, ?string $content, Collection $media, bool $attachLink): array
    {
        if ($media->isEmpty()) {
            return $this->publishTextPost($pageId, $accessToken, $content, $attachLink);
        }

        $firstMedia = $media->first();

        return match (true) {
            $firstMedia->isVideo() => $this->publishVideoPost($pageId, $accessToken, $content, $firstMedia),
            $firstMedia->isImage() && $media->count() === 1 => $this->publishSingleImagePost($pageId, $accessToken, $content, $firstMedia),
            $firstMedia->isImage() => $this->publishMultiImagePost($pageId, $accessToken, $content, $media),
            default => throw new FacebookPublishException(
                userMessage: 'Unsupported media type for Facebook',
                category: ErrorCategory::MediaFormat,
            ),
        };
    }

    /**
     * @return array{id: mixed, url: string}
     */
    private function publishTextPost(string $pageId, string $accessToken, ?string $content, bool $attachLink): array
    {
        if (blank($content)) {
            throw new FacebookPublishException(
                userMessage: 'Facebook text posts require content. Please add text to your post.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $link = $attachLink ? FacebookLinkPreview::url($content) : null;

        if (blank($link)) {
            return $this->feedPostResult($this->postTextToFeed($pageId, $accessToken, $content, null)->json('id'));
        }

        return $this->feedPostResult($this->postTextWithLink($pageId, $accessToken, $content, $link)->json('id'));
    }

    /**
     * Facebook can reject the link and not the post: 1609005 (scrape failed),
     * 1500 (invalid URL) or 200/1609008 (facebook.com URL). The caption
     * published as plain text before `link` existed, so on those codes the
     * card is dropped and the text is posted once more. Any other error fails
     * the post. The first attempt stays quiet in the log because only the
     * outcome of the retry says whether the publish failed.
     */
    private function postTextWithLink(string $pageId, string $accessToken, string $content, string $link): Response
    {
        try {
            return $this->postTextToFeed($pageId, $accessToken, $content, $link, reportFailure: false);
        } catch (FacebookPublishException $exception) {
            if (! $exception->rejectsLink()) {
                Log::error('Facebook text post failed', [
                    'platform_error_code' => $exception->platformErrorCode,
                    'body' => $this->redactResponseBody($exception->rawResponse ?? ''),
                ]);

                throw $exception;
            }

            Log::warning('Facebook rejected the link preview; publishing the text without it', [
                'platform_error_code' => $exception->platformErrorCode,
                'platform_error_subcode' => $exception->platformErrorSubcode,
            ]);
        }

        return $this->postTextToFeed($pageId, $accessToken, $content, null);
    }

    /**
     * A text post carries `link` when the caption contains a URL. Facebook does
     * not unfurl a URL left only in `message`; the Page Feed `link` field is
     * what makes it scrape Open Graph and render the preview.
     */
    private function postTextToFeed(string $pageId, string $accessToken, string $content, ?string $link, bool $reportFailure = true): Response
    {
        return $this->postToGraph("{$pageId}/feed", [
            'message' => $content,
            'access_token' => $accessToken,
            'link' => $link,
        ], 'text post', $reportFailure);
    }

    /**
     * @return array{id: mixed, url: string}
     */
    private function publishSingleImagePost(string $pageId, string $accessToken, ?string $content, MediaItem $media): array
    {
        $response = $this->postToGraph("{$pageId}/photos", [
            'url' => $media->url,
            'access_token' => $accessToken,
            'message' => $content,
            'alt_text_custom' => $media->altTextFor(Platform::Facebook),
        ], 'single image post');

        return $this->feedPostResult($response->json('post_id') ?? $response->json('id'));
    }

    /**
     * Every image is uploaded unpublished and then attached to one feed post. An
     * image Facebook rejects is skipped so the rest still goes out; the post only
     * fails when none of them made it.
     *
     * @param  Collection<int, MediaItem>  $media
     * @return array{id: mixed, url: string}
     */
    private function publishMultiImagePost(string $pageId, string $accessToken, ?string $content, Collection $media): array
    {
        $photoIds = $media
            ->filter(fn (MediaItem $item): bool => $item->isImage())
            ->map(fn (MediaItem $item): ?string => $this->uploadUnpublishedPhoto($pageId, $accessToken, $item))
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
            'message' => $content,
            ...$photoIds
                ->mapWithKeys(fn (string $photoId, int $index): array => ["attached_media[{$index}]" => json_encode(['media_fbid' => $photoId])])
                ->all(),
        ], 'multi-image post');

        return $this->feedPostResult($response->json('id'));
    }

    private function uploadUnpublishedPhoto(string $pageId, string $accessToken, MediaItem $media, ?string $url = null): ?string
    {
        $response = $this->reachOrRetry(
            fn (): Response => $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/photos", $this->filledFields([
                'url' => $url ?? $media->url,
                'published' => 'false',
                'access_token' => $accessToken,
                'alt_text_custom' => $media->altTextFor(Platform::Facebook),
            ])),
            'image upload',
        );

        $photoId = $response->json('id');

        if ($response->failed() || ! is_string($photoId) || blank($photoId)) {
            Log::error('Facebook image upload failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        return $photoId;
    }

    /**
     * @return array{id: mixed, url: string, facebook_cover_warning?: true}
     */
    private function publishVideoPost(string $pageId, string $accessToken, ?string $content, MediaItem $media): array
    {
        $payload = [
            'file_url' => $media->url,
            'access_token' => $accessToken,
            'description' => $content,
        ];
        $thumb = $this->feedVideoThumb($media);
        $coverSkipped = blank($thumb) && filled($media->cover());

        try {
            $response = $this->postToGraph("{$pageId}/videos", $payload, 'video post', reportFailure: false, image: $thumb);
        } catch (FacebookPublishException $exception) {
            if (! $this->isThumbRefusal($thumb, $exception)) {
                Log::error('Facebook video post failed', [
                    'platform_error_code' => $exception->platformErrorCode,
                    'body' => $this->redactResponseBody($exception->rawResponse ?? ''),
                ]);

                throw $exception;
            }

            $response = $this->postToGraph("{$pageId}/videos", $payload, 'video post');
            $coverSkipped = true;
        }

        $videoId = $response->json('id');
        $result = [
            'id' => $videoId,
            'url' => "https://www.facebook.com/{$pageId}/videos/{$videoId}",
        ];

        if ($coverSkipped) {
            return [...$result, FacebookCover::WARNING => true];
        }

        return $result;
    }

    /**
     * Whether a refused upload is worth sending again without its `thumb`: it
     * carried one, and Graph refused a parameter (code 100). Meta documents
     * no code of its own for a refused thumbnail; any other refusal (the
     * file, a limit, an outage, a policy) would hold the second request too,
     * and resending after a transient one could post the video twice.
     */
    private function isThumbRefusal(?string $thumb, FacebookPublishException $exception): bool
    {
        if (blank($thumb)) {
            return false;
        }

        return $exception->rejectsParameter();
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
            'description' => $content,
        ], 'reel finish');

        $reelId = $response->json('id') ?? $videoId;

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
        if ($media->isImage()) {
            return $this->publishPhotoStory($pageId, $accessToken, $media);
        }

        $videoId = $this->uploadVideo($pageId, $accessToken, 'video_stories', $media);

        $response = $this->postToGraph("{$pageId}/video_stories", [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'access_token' => $accessToken,
        ], 'story finish');

        return $this->storyResult($pageId, $response->json('post_id') ?? $videoId);
    }

    /**
     * A photo story is an unpublished Page photo handed to `photo_stories`
     * (https://developers.facebook.com/docs/page-stories-api/). The image is
     * fitted into the 9:16 story frame first, like Instagram stories, so an
     * off-ratio photo is not cropped by the network.
     *
     * @return array{id: mixed, url: string}
     */
    private function publishPhotoStory(string $pageId, string $accessToken, MediaItem $media): array
    {
        $dimensions = ContentType::FacebookStory->preferredImageDimensions();
        $fittedUrl = $this->fitImageToCanvas($media->url, data_get($dimensions, 'width'), data_get($dimensions, 'height'));

        $photoId = $this->uploadUnpublishedPhoto($pageId, $accessToken, $media, $fittedUrl);

        if (blank($photoId)) {
            throw new FacebookPublishException(
                userMessage: 'Facebook could not upload the story photo.',
                category: ErrorCategory::ServerError,
            );
        }

        $response = $this->postToGraph("{$pageId}/photo_stories", [
            'photo_id' => $photoId,
            'access_token' => $accessToken,
        ], 'photo story');

        return $this->storyResult($pageId, $response->json('post_id') ?? $photoId);
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

        $videoId = $response->json('video_id');
        $uploadUrl = $response->json('upload_url');

        if (blank($videoId) || ! is_string($uploadUrl) || blank($uploadUrl)) {
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
        $uri = rescue(fn (): Uri => Uri::of($uploadUrl), report: false);

        if (blank($uri) || $uri->scheme() !== 'https' || $uri->host() !== config('trypost.platforms.facebook.rupload_host')) {
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

            if (FacebookPublishException::isRetryableUpload($response)) {
                throw new PlatformUnavailableException(
                    message: "Facebook video upload failed with {$response->status()}",
                    httpStatus: $response->status(),
                );
            }

            $this->handleApiError($response);
        }

        if ($response->json('success') !== true) {
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

            $response = $this->readVideoStatus($videoId, $accessToken, 'video status');

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

            $status = $response->json('status', []);
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

        if (is_string($detail) && filled($detail)) {
            return $detail;
        }

        if ($this->videoStatus($status)?->hasFailed() === true) {
            return self::VIDEO_PROCESSING_FAILED;
        }

        if (data_get($status, 'uploading_phase.status') === 'error') {
            return self::VIDEO_PROCESSING_FAILED;
        }

        if (data_get($status, 'processing_phase.status') === 'error') {
            return self::VIDEO_PROCESSING_FAILED;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function videoUploadComplete(array $status): bool
    {
        if (data_get($status, 'uploading_phase.status') === 'complete') {
            return true;
        }

        return $this->videoStatus($status)?->isUploaded() === true;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function videoStatus(array $status): ?VideoStatus
    {
        return VideoStatus::tryFrom((string) data_get($status, 'video_status'));
    }

    private function requireMedia(?MediaItem $media, string $format): MediaItem
    {
        if (blank($media) || (! $media->isVideo() && ! $media->isImage())) {
            throw new FacebookPublishException(
                userMessage: "Facebook {$format} require an image or a video file.",
                category: ErrorCategory::MediaFormat,
            );
        }

        return $media;
    }

    private function requireVideo(?MediaItem $media, string $format): MediaItem
    {
        if (blank($media) || ! $media->isVideo()) {
            throw new FacebookPublishException(
                userMessage: "Facebook {$format} require a video file.",
                category: ErrorCategory::MediaFormat,
            );
        }

        return $media;
    }

    protected function cropFailureException(string $message): SocialPublishException
    {
        return new FacebookPublishException(
            userMessage: $message,
            category: ErrorCategory::ServerError,
        );
    }

    private function sanitizedContent(Post $post): ?string
    {
        return transform(
            $post->content,
            fn (string $content): string => app(ContentSanitizer::class)->sanitize($content, $post->platform),
        );
    }

    /**
     * Graph API expects application/x-www-form-urlencoded (or multipart), not JSON.
     * Sending JSON makes `message` work but silently drops `attached_media[*]` on /feed.
     */
    private function facebookHttp(): PendingRequest
    {
        return $this->socialHttp()->asForm();
    }

    /** The form request, or a multipart one carrying a JPEG as `$field`. */
    private function graphRequestWithImage(?string $image, string $field): PendingRequest
    {
        if (blank($image)) {
            return $this->facebookHttp();
        }

        return $this->socialHttp()->attach($field, $image, 'cover.jpg', ['Content-Type' => self::JPEG_MIME_TYPE]);
    }

    /**
     * A feed video's cover (`media.*.meta.cover`: the uploaded image, or the
     * picked frame's screenshot) as JPEG bytes for `thumb`. A cover that
     * cannot be downloaded or converted publishes the video with the cover
     * Facebook picks, never fails it.
     */
    private function feedVideoThumb(MediaItem $media): ?string
    {
        $cover = $media->cover();

        if (blank($cover)) {
            return null;
        }

        try {
            return $this->coverJpeg($cover);
        } catch (FacebookPublishException) {
            return null;
        }
    }

    /**
     * Whether Facebook is still processing the published Reel, which may not
     * take a cover yet: every status but `ready` counts as processing
     * (`upload_complete` comes before it). A status read that fails
     * transiently counts as still processing, so the cover waits for the
     * next try.
     *
     * @throws FacebookPublishException when Facebook refuses the read, or the Reel will never be ready (MediaFormat)
     */
    public function reelIsProcessing(Post $post): bool
    {
        $response = $this->readVideoStatus($post->platform_post_id, $post->socialAccount->access_token, 'reel status');

        if ($response->failed()) {
            if (! GraphError::isTransientFailure($response)) {
                $this->handleApiError($response);
            }

            return true;
        }

        $videoStatus = $this->videoStatus((array) $response->json('status'));

        if ($videoStatus?->hasFailed() === true) {
            throw new FacebookPublishException(
                userMessage: "Facebook could not process the Reel ({$videoStatus->value})",
                category: ErrorCategory::MediaFormat,
            );
        }

        return $videoStatus !== VideoStatus::Ready;
    }

    /**
     * Sets a published Reel's cover image (`media.0.meta.cover`) as its
     * preferred thumbnail. The Reel takes no cover when it is published, so
     * this runs once it is up (`SetFacebookReelCover`).
     *
     * @see https://developers.facebook.com/docs/graph-api/reference/video/thumbnails/
     *
     * @throws FacebookPublishException when the cover cannot be downloaded (ServerError, tried again) or converted (MediaFormat), or Facebook refuses it
     */
    public function setReelCover(Post $post): void
    {
        $cover = $post->mediaItems->first()?->cover();

        if (blank($cover)) {
            return;
        }

        $this->postToGraph("{$post->platform_post_id}/thumbnails", [
            'access_token' => $post->socialAccount->access_token,
            'is_preferred' => 'true',
        ], 'reel cover', image: $this->coverJpeg($cover), imageField: 'source');
    }

    /**
     * The cover image as JPEG bytes within Facebook's thumbnail limit.
     *
     * @throws FacebookPublishException when it cannot be downloaded (ServerError) or converted (MediaFormat)
     */
    private function coverJpeg(MediaItem $cover): string
    {
        $download = $this->downloadImage($cover->url) ?? throw new FacebookPublishException(
            userMessage: 'Failed to download the cover image for Facebook',
            category: ErrorCategory::ServerError,
        );

        try {
            return $this->convertToJpegBytes($download, Platform::Facebook, self::COVER_MAX_BYTES);
        } catch (SocialPublishException $exception) {
            throw new FacebookPublishException(
                userMessage: $exception->getMessage(),
                category: ErrorCategory::MediaFormat,
            );
        } finally {
            File::delete($download);
        }
    }

    private function readVideoStatus(string $videoId, string $accessToken, string $label): Response
    {
        return $this->reachOrRetry(
            fn (): Response => $this->socialHttp()->get("{$this->baseUrl}/{$videoId}", [
                'fields' => 'status',
                'access_token' => $accessToken,
            ]),
            $label,
        );
    }

    /**
     * POST a form payload to a Graph edge and turn any failure into the typed
     * exception, logging the redacted body under `$label` first.
     *
     * An `$image` (JPEG bytes) goes as a multipart file named `$imageField`
     * next to the payload. Blank fields are left out.
     *
     * @param  array<string, string|null>  $payload
     */
    private function postToGraph(string $path, array $payload, string $label, bool $reportFailure = true, ?string $image = null, string $imageField = 'thumb'): Response
    {
        $response = $this->reachOrRetry(
            fn (): Response => $this->graphRequestWithImage($image, $imageField)->post("{$this->baseUrl}/{$path}", $this->filledFields($payload)),
            $label,
        );

        if ($response->failed()) {
            if ($reportFailure) {
                Log::error("Facebook {$label} failed", [
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);
            }

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
     * The fields without the blank ones (no caption, no alt text, no link), which the request leaves out.
     *
     * @param  array<string, string|null>  $fields
     * @return array<string, string>
     */
    private function filledFields(array $fields): array
    {
        return Arr::where($fields, fn (?string $value): bool => filled($value));
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

    /**
     * @return array{id: mixed, url: string}
     */
    private function storyResult(string $pageId, mixed $storyId): array
    {
        return [
            'id' => $storyId,
            'url' => "https://www.facebook.com/stories/{$pageId}/{$storyId}",
        ];
    }

    private function handleApiError(Response $response): never
    {
        throw FacebookPublishException::fromApiResponse($response);
    }
}

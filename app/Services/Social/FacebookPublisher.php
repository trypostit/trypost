<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\CropsImageForAspectRatio;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookPublisher
{
    use CropsImageForAspectRatio;
    use HasSocialHttpClient;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.facebook.graph_api');
    }

    /**
     * Graph API expects application/x-www-form-urlencoded (or multipart), not JSON.
     * Sending JSON makes `message` work but silently drops `attached_media[*]` on /feed.
     */
    private function facebookHttp(): PendingRequest
    {
        return $this->socialHttp()->asForm();
    }

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $account = $postPlatform->socialAccount;
        $pageId = $account->platform_user_id;
        $accessToken = $account->access_token;

        $media = $postPlatform->post->mediaItems;
        $contentType = $postPlatform->content_type;
        $aspectRatio = data_get($postPlatform->meta, 'aspect_ratio');

        return match ($contentType) {
            ContentType::FacebookReel => $this->publishReel($pageId, $accessToken, $content, $media->first()),
            ContentType::FacebookStory => $this->publishStory($pageId, $accessToken, $media->first()),
            ContentType::FacebookPost => $this->publishPost($pageId, $accessToken, $content, $media, $aspectRatio),
            default => throw new FacebookPublishException(
                userMessage: "Unsupported Facebook content type: {$contentType?->value}",
                category: ErrorCategory::MediaFormat,
            ),
        };
    }

    private function publishPost(string $pageId, string $accessToken, ?string $content, $media, ?string $aspectRatio): array
    {
        // Text only post
        if ($media->isEmpty()) {
            if ($content === null || $content === '') {
                throw new FacebookPublishException(
                    userMessage: 'Facebook text posts require content. Please add text to your post.',
                    category: ErrorCategory::MediaFormat,
                );
            }

            return $this->publishTextPost($pageId, $accessToken, $content);
        }

        $firstMedia = $media->first();
        $isVideo = $firstMedia->isVideo();
        $isImage = $firstMedia->isImage();

        if ($isVideo) {
            return $this->publishVideoPost($pageId, $accessToken, $content, $firstMedia);
        }

        if ($isImage) {
            // Single or multiple images
            if ($media->count() === 1) {
                return $this->publishSingleImagePost($pageId, $accessToken, $content, $firstMedia, $aspectRatio);
            }

            return $this->publishMultiImagePost($pageId, $accessToken, $content, $media, $aspectRatio);
        }

        throw new FacebookPublishException(
            userMessage: 'Unsupported media type for Facebook',
            category: ErrorCategory::MediaFormat,
        );
    }

    private function publishTextPost(string $pageId, string $accessToken, string $content): array
    {
        $response = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/feed", [
            'message' => $content,
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            Log::error('Facebook text post failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();
        $postId = data_get($data, 'id');

        return [
            'id' => $postId,
            'url' => "https://www.facebook.com/{$postId}",
        ];
    }

    private function publishSingleImagePost(string $pageId, string $accessToken, ?string $content, $media, ?string $aspectRatio): array
    {
        $payload = [
            'url' => $this->cropImageForAspectRatio($media->url, $aspectRatio),
            'access_token' => $accessToken,
        ];

        if ($content !== null && $content !== '') {
            $payload['message'] = $content;
        }

        $alt = $media->altTextFor(Platform::Facebook);

        if ($alt !== null) {
            $payload['alt_text_custom'] = $alt;
        }

        $response = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/photos", $payload);

        if ($response->failed()) {
            Log::error('Facebook single image post failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();
        $postId = data_get($data, 'post_id', data_get($data, 'id'));

        return [
            'id' => $postId,
            'url' => "https://www.facebook.com/{$postId}",
        ];
    }

    private function publishMultiImagePost(string $pageId, string $accessToken, ?string $content, $mediaCollection, ?string $aspectRatio): array
    {
        // Upload each image as unpublished
        $attachedMedia = [];

        foreach ($mediaCollection as $media) {
            if (! $media->isImage()) {
                continue;
            }

            $uploadPayload = [
                'url' => $this->cropImageForAspectRatio($media->url, $aspectRatio),
                'published' => 'false',
                'access_token' => $accessToken,
            ];

            $alt = $media->altTextFor(Platform::Facebook);

            if ($alt !== null) {
                $uploadPayload['alt_text_custom'] = $alt;
            }

            $uploadResponse = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/photos", $uploadPayload);

            if ($uploadResponse->failed()) {
                Log::error('Facebook image upload failed', [
                    'body' => $this->redactResponseBody($uploadResponse->body()),
                ]);

                continue;
            }

            $uploadData = $uploadResponse->json();
            $attachedMedia[] = ['media_fbid' => $uploadData['id']];
        }

        if (empty($attachedMedia)) {
            throw new FacebookPublishException(
                userMessage: 'Failed to upload any images to Facebook',
                category: ErrorCategory::ServerError,
            );
        }

        // Create the post with attached media
        $postData = [
            'access_token' => $accessToken,
        ];

        if ($content !== null && $content !== '') {
            $postData['message'] = $content;
        }

        foreach ($attachedMedia as $index => $media) {
            $postData["attached_media[{$index}]"] = json_encode($media);
        }

        $response = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/feed", $postData);

        if ($response->failed()) {
            Log::error('Facebook multi-image post failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();
        $postId = data_get($data, 'id');

        return [
            'id' => $postId,
            'url' => "https://www.facebook.com/{$postId}",
        ];
    }

    private function publishVideoPost(string $pageId, string $accessToken, ?string $content, $media): array
    {
        $payload = [
            'file_url' => $media->url,
            'access_token' => $accessToken,
        ];

        if ($content !== null && $content !== '') {
            $payload['description'] = $content;
        }

        $response = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/videos", $payload);

        if ($response->failed()) {
            Log::error('Facebook video post failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();
        $videoId = data_get($data, 'id');

        return [
            'id' => $videoId,
            'url' => "https://www.facebook.com/{$pageId}/videos/{$videoId}",
        ];
    }

    private function publishReel(string $pageId, string $accessToken, ?string $content, $media): array
    {
        // Phase 1 (start) — graph endpoint returns video_id + upload_url.
        $startResponse = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/video_reels", [
            'upload_phase' => 'start',
            'access_token' => $accessToken,
        ]);

        if ($startResponse->failed()) {
            $this->handleApiError($startResponse);
        }

        $startData = $startResponse->json();
        $videoId = data_get($startData, 'video_id');
        $uploadUrl = data_get($startData, 'upload_url');

        if (! $videoId || ! $uploadUrl) {
            throw new FacebookPublishException(
                userMessage: 'Facebook did not return upload_url for reel start.',
                category: ErrorCategory::ServerError,
                platformErrorCode: null,
                rawResponse: $startResponse->body(),
            );
        }

        // Phase 2 (transfer, local-file flow) — download our hosted
        // media then POST raw bytes to upload_url with the Offset and
        // file_size headers Facebook requires (the docs describe a
        // hosted-file shortcut with `file_url` in the body, but rupload
        // rejects it with "Header Offset not convertable to unsigned
        // long" — the headers are required either way).
        $tempFile = tempnam(sys_get_temp_dir(), 'fb_reel_');

        try {
            $download = Http::withOptions(['sink' => $tempFile])
                ->timeout(600)
                ->get($media->url);

            if ($download->failed()) {
                throw new FacebookPublishException(
                    userMessage: 'Could not download media for Facebook reel.',
                    category: ErrorCategory::ServerError,
                    platformErrorCode: (string) $download->status(),
                    rawResponse: null,
                );
            }

            $fileSize = filesize($tempFile);
            $stream = fopen($tempFile, 'rb');

            try {
                $uploadResponse = Http::withHeaders([
                    'Authorization' => "OAuth {$accessToken}",
                    'Offset' => '0',
                    'file_size' => (string) $fileSize,
                ])
                    ->timeout(600)
                    ->withBody($stream, $media->mime_type ?? 'video/mp4')
                    ->post($uploadUrl);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($uploadResponse->failed()) {
                $this->handleApiError($uploadResponse);
            }
        } finally {
            if (! unlink($tempFile)) {
                Log::warning('Facebook reel temp file cleanup failed', ['path' => $tempFile]);
            }
        }

        // Phase 3 (finish) — publish the reel.
        $finishPayload = [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'video_state' => 'PUBLISHED',
            'access_token' => $accessToken,
        ];

        if ($content !== null && $content !== '') {
            $finishPayload['description'] = $content;
        }

        $finishResponse = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/video_reels", $finishPayload);

        if ($finishResponse->failed()) {
            $this->handleApiError($finishResponse);
        }

        $finishData = $finishResponse->json();
        $reelId = $finishData['id'] ?? $videoId;

        return [
            'id' => $reelId,
            'url' => "https://www.facebook.com/reel/{$reelId}",
        ];
    }

    private function publishStory(string $pageId, string $accessToken, $media): array
    {
        if (! $media->isVideo()) {
            throw new FacebookPublishException(
                userMessage: 'Facebook Stories require a video file.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $response = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/video_stories", [
            'upload_phase' => 'start',
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            $this->handleApiError($response);
        }

        $videoId = $response->json()['video_id'] ?? null;

        if (! $videoId) {
            throw new FacebookPublishException(
                userMessage: 'Facebook did not accept the story video. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        $transferResponse = $this->facebookHttp()->post("{$this->baseUrl}/{$videoId}", [
            'upload_phase' => 'transfer',
            'video_file_chunk' => $media->url,
            'access_token' => $accessToken,
        ]);

        if ($transferResponse->failed()) {
            Log::error('Facebook video story transfer failed', ['body' => $this->redactResponseBody($transferResponse->body())]);
            $this->handleApiError($transferResponse);
        }

        $finishResponse = $this->facebookHttp()->post("{$this->baseUrl}/{$pageId}/video_stories", [
            'upload_phase' => 'finish',
            'video_id' => $videoId,
            'access_token' => $accessToken,
        ]);

        if ($finishResponse->failed()) {
            $this->handleApiError($finishResponse);
        }

        $storyId = $finishResponse->json()['post_id'] ?? $videoId;

        return [
            'id' => $storyId,
            'url' => "https://www.facebook.com/stories/{$pageId}/{$storyId}",
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

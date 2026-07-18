<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\DataTransferObjects\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\XPublishException;
use App\Models\PostPlatform;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class XPublisher
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.x.api');
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

        $data = [];

        if (! empty($content)) {
            $data['text'] = $content;
        }

        $mediaIds = [];
        $media = $postPlatform->post->mediaItems;

        if ($media->isNotEmpty()) {
            foreach ($media as $mediaItem) {
                $uploadedMedia = $this->uploadMedia($mediaItem);

                // v2 API returns data.id, v1 returns media_id
                $mediaId = data_get($uploadedMedia, 'data.id', data_get($uploadedMedia, 'media_id'));
                if ($mediaId) {
                    $mediaIds[] = $mediaId;
                    $this->uploadAltText($mediaId, $mediaItem);
                }
            }
        }

        if (! empty($mediaIds)) {
            $data['media'] = [
                'media_ids' => $mediaIds,
            ];
        }

        if (empty($content) && empty($mediaIds)) {
            throw new XPublishException(
                userMessage: 'X posts require either text or media. Please add content to your post.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/tweets", $data);

        if ($response->failed()) {
            Log::error('X post creation failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $responseData = $response->json();
        $tweetId = $responseData['data']['id'] ?? null;

        return [
            'id' => $tweetId ?? 'unknown',
            'url' => $tweetId ? "https://x.com/{$account->username}/status/{$tweetId}" : null,
        ];
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->withToken($this->accessToken)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Sets the image's accessibility description on X via the v2 media
     * metadata endpoint. Best-effort: only images carry alt text, and a failure
     * here never blocks the tweet — the media already uploaded and the post
     * should still go out without the description.
     */
    private function uploadAltText(string $mediaId, MediaItem $mediaItem): void
    {
        if (! $mediaItem->isImage()) {
            return;
        }

        $alt = $mediaItem->altTextFor(Platform::X);

        if ($alt === null) {
            return;
        }

        try {
            $this->getHttpClient()->post("{$this->baseUrl}/media/metadata", [
                'id' => $mediaId,
                'metadata' => [
                    'alt_text' => [
                        'text' => $alt,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            Log::warning('X alt text upload failed; posting the tweet without it', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function uploadMedia($mediaItem): ?array
    {
        $mimeType = $mediaItem->mime_type;

        // Download to temp file (memory-safe)
        $tempFile = tempnam(sys_get_temp_dir(), 'x_media_');

        try {
            $downloadResponse = Http::withOptions(['sink' => $tempFile])->timeout(600)->get($mediaItem->url);

            if ($downloadResponse->failed()) {
                throw new XPublishException(
                    userMessage: 'Could not fetch the media to upload to X. Please try again.',
                    category: ErrorCategory::ServerError,
                );
            }

            if (blank($mimeType)) {
                $mimeType = mime_content_type($tempFile) ?: null;
            }

            if (blank($mimeType)) {
                throw new XPublishException(
                    userMessage: 'Unsupported media type for X.',
                    category: ErrorCategory::MediaFormat,
                );
            }

            // Optimize images (skip GIFs — they need special handling)
            if (MediaType::classify($mimeType) === MediaType::Image && ! MediaType::isGif($mimeType)) {
                $optimizer = app(MediaOptimizer::class);
                $optimizedPath = $optimizer->optimizeImage($tempFile, Platform::X);
                @unlink($tempFile);
                $tempFile = $optimizedPath;
                $mimeType = 'image/jpeg';
            }

            $fileSize = filesize($tempFile);
            $mediaCategory = $this->getMediaCategory($mimeType, $fileSize);

            $isVideo = MediaType::classify($mimeType) === MediaType::Video;
            $isGif = MediaType::isGif($mimeType);

            $useChunkedUpload = $isVideo || $isGif || $fileSize > 5 * 1024 * 1024;

            if ($useChunkedUpload) {
                return $this->chunkedUpload($tempFile, $fileSize, $mimeType, $mediaCategory);
            }

            // Simple upload for small images
            $response = $this->socialHttp()->withToken($this->accessToken)
                ->timeout(360)
                ->attach(
                    'media',
                    fopen($tempFile, 'r'),
                    basename($tempFile),
                    ['Content-Type' => $mimeType]
                );

            $formParams = [];
            if ($mediaCategory) {
                $formParams['media_category'] = $mediaCategory;
            }

            $response = $response->post("{$this->baseUrl}/media/upload", $formParams);

            if ($response->failed()) {
                Log::error('X media upload error', [
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);
                $this->handleApiError($response);
            }

            $responseData = $response->json();

            $mediaId = $responseData['data']['id'] ?? $responseData['media_id'] ?? null;

            if ($isGif && $mediaId) {
                $this->waitForProcessing($mediaId);
            }

            return $responseData;
        } finally {
            @unlink($tempFile);
        }
    }

    private function chunkedUpload(string $tempFile, int $totalBytes, string $mimeType, ?string $mediaCategory): array
    {
        $initPayload = [
            'media_type' => $mimeType,
            'total_bytes' => $totalBytes,
        ];

        if ($mediaCategory) {
            $initPayload['media_category'] = $mediaCategory;
        }

        // INIT
        $initResponse = $this->socialHttp()->withToken($this->accessToken)
            ->timeout(60)
            ->post("{$this->baseUrl}/media/upload/initialize", $initPayload);

        if ($initResponse->failed()) {
            Log::error('X chunked upload INIT error', [
                'status' => $initResponse->status(),
                'body' => $this->redactResponseBody($initResponse->body()),
            ]);
            $this->handleApiError($initResponse);
        }

        $initData = $initResponse->json();
        $mediaId = $initData['data']['id'] ?? $initData['media_id'] ?? null;

        if (! $mediaId) {
            throw new XPublishException(
                userMessage: 'X did not accept the media upload. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        // APPEND - Read from temp file in 1MB chunks. Matches the
        // twitter-api-v2 SDK default and X's own quickstart examples;
        // larger chunks (we previously used 5MB) trigger 413 at the X
        // edge with an empty body, surfacing as "An unknown X error".
        $chunkSize = 1024 * 1024;
        $handle = fopen($tempFile, 'r');
        $index = 0;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, $chunkSize);

                if ($chunk === '' || $chunk === false) {
                    break;
                }

                $appendResponse = $this->socialHttp()->withToken($this->accessToken)
                    ->timeout(300)
                    ->attach('media', $chunk, 'chunk'.$index, ['Content-Type' => $mimeType])
                    ->post("{$this->baseUrl}/media/upload/{$mediaId}/append", [
                        'segment_index' => $index,
                    ]);

                if ($appendResponse->failed()) {
                    Log::error('X chunked upload APPEND error', [
                        'status' => $appendResponse->status(),
                        'body' => $this->redactResponseBody($appendResponse->body()),
                        'segment' => $index,
                    ]);
                    $this->handleApiError($appendResponse);
                }

                $index++;

                unset($chunk, $appendResponse);
                $this->freeChunkMemory();
            }
        } finally {
            fclose($handle);
        }

        // FINALIZE - Use the new v2 endpoint
        $finalizeResponse = $this->socialHttp()->withToken($this->accessToken)
            ->timeout(60)
            ->post("{$this->baseUrl}/media/upload/{$mediaId}/finalize");

        if ($finalizeResponse->failed()) {
            Log::error('X chunked upload FINALIZE error', [
                'status' => $finalizeResponse->status(),
                'body' => $this->redactResponseBody($finalizeResponse->body()),
            ]);
            $this->handleApiError($finalizeResponse);
        }

        $finalizeData = $finalizeResponse->json();

        // Wait for processing (videos need transcoding)
        if (isset($finalizeData['processing_info']) || MediaType::classify($mimeType) === MediaType::Video) {
            $this->waitForProcessing($mediaId);
        }

        // Return in same format as simple upload
        return [
            'data' => [
                'id' => $mediaId,
            ],
        ];
    }

    private function getMediaCategory(string $mimeType, int $fileSize): ?string
    {
        if (MediaType::classify($mimeType) === MediaType::Video) {
            return $fileSize > 15 * 1024 * 1024 ? 'amplify_video' : 'tweet_video';
        }

        if (MediaType::isGif($mimeType)) {
            return 'tweet_gif';
        }

        if (MediaType::classify($mimeType) === MediaType::Image) {
            return 'tweet_image';
        }

        return null;
    }

    private function waitForProcessing(string $mediaId, int $maxAttempts = 20): bool
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->getHttpClient()
                ->get("{$this->baseUrl}/media/{$mediaId}");

            if ($response->failed()) {
                Log::error('X media status check error', ['body' => $this->redactResponseBody($response->body())]);
                sleep(3);

                continue;
            }

            $responseData = $response->json();

            // If processing_info doesn't exist, assume it's ready
            if (! isset($responseData['processing_info'])) {
                return true;
            }

            $state = $responseData['processing_info']['state'] ?? 'unknown';

            if ($state === 'succeeded') {
                return true;
            }

            if ($state === 'failed') {
                $error = $responseData['processing_info']['error'] ?? 'Unknown error';
                Log::error('X media processing failed: '.$error);

                return false;
            }

            // Wait before checking again
            $waitTime = $responseData['processing_info']['check_after_secs'] ?? 3;
            sleep($waitTime);
        }

        return false;
    }

    private function handleApiError(Response $response): never
    {
        throw XPublishException::fromApiResponse($response);
    }
}

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

        $content = $postPlatform->resolvedContent() ? app(ContentSanitizer::class)->sanitize($postPlatform->resolvedContent(), $postPlatform->platform) : null;

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
                    // X expects media_ids as strings in the tweets payload.
                    $mediaIds[] = (string) $mediaId;
                    $this->uploadAltText((string) $mediaId, $mediaItem);
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

            return $response->json();
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

        // INIT — X requires application/json (MediaUploadConfigRequest).
        $initResponse = $this->socialHttp()->withToken($this->accessToken)
            ->timeout(60)
            ->asJson()
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

        // FINALIZE — runtime rejects empty / non-JSON bodies with
        // "Request body must be a JSON object." Send `{}` explicitly
        // (empty array encodes as `[]`, which is not an object).
        $finalizeResponse = $this->socialHttp()->withToken($this->accessToken)
            ->timeout(60)
            ->withBody('{}', 'application/json')
            ->post("{$this->baseUrl}/media/upload/{$mediaId}/finalize");

        if ($finalizeResponse->failed()) {
            Log::error('X chunked upload FINALIZE error', [
                'status' => $finalizeResponse->status(),
                'body' => $this->redactResponseBody($finalizeResponse->body()),
            ]);
            $this->handleApiError($finalizeResponse);
        }

        $finalizeData = $finalizeResponse->json();

        // Videos/GIFs (and any finalize that reports processing_info) must finish
        // before we attach the media_id to a tweet — otherwise X returns
        // invalid-request / invalid media IDs.
        $processingInfo = data_get($finalizeData, 'data.processing_info')
            ?? data_get($finalizeData, 'processing_info');

        if (
            $processingInfo !== null
            || MediaType::classify($mimeType) === MediaType::Video
            || MediaType::isGif($mimeType)
        ) {
            $this->waitForProcessing((string) $mediaId);
        }

        // Return in same format as simple upload
        return [
            'data' => [
                'id' => (string) $mediaId,
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

    private function waitForProcessing(string $mediaId, int $maxAttempts = 20): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            // Official status endpoint: GET /2/media/upload?media_id=...&command=STATUS
            // (not GET /2/media/{id} — that path is not the upload-status contract).
            $response = $this->getHttpClient()
                ->get("{$this->baseUrl}/media/upload", [
                    'media_id' => $mediaId,
                    'command' => 'STATUS',
                ]);

            if ($response->failed()) {
                Log::error('X media status check error', ['body' => $this->redactResponseBody($response->body())]);
                sleep(3);

                continue;
            }

            $responseData = $response->json();
            $processingInfo = data_get($responseData, 'processing_info')
                ?? data_get($responseData, 'data.processing_info');

            // If processing_info doesn't exist, assume it's ready
            if ($processingInfo === null) {
                return;
            }

            $state = data_get($processingInfo, 'state', 'unknown');

            if ($state === 'succeeded') {
                return;
            }

            if ($state === 'failed') {
                $error = data_get($processingInfo, 'error', 'Unknown error');
                $rawError = is_string($error) ? $error : json_encode($error);

                Log::error('X media processing failed: '.$rawError);

                throw new XPublishException(
                    userMessage: 'X could not process the uploaded media. Please try a different file.',
                    category: ErrorCategory::MediaFormat,
                    platformErrorCode: 'media-processing-failed',
                    rawResponse: $rawError ?: null,
                );
            }

            // Wait before checking again
            $waitTime = (int) data_get($processingInfo, 'check_after_secs', 3);
            sleep(max(0, $waitTime));
        }

        throw new XPublishException(
            userMessage: 'X media processing timed out. Please try again.',
            category: ErrorCategory::ServerError,
            platformErrorCode: 'media-processing-timeout',
        );
    }

    private function handleApiError(Response $response): never
    {
        throw XPublishException::fromApiResponse($response);
    }
}

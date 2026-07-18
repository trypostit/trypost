<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\PinterestPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PinterestPublisher
{
    use HasSocialHttpClient;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.pinterest.api');
    }

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $account = $postPlatform->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        return match ($postPlatform->content_type) {
            ContentType::PinterestPin => $this->publishImagePin($postPlatform, $content),
            ContentType::PinterestVideoPin => $this->publishVideoPin($postPlatform, $content),
            ContentType::PinterestCarousel => $this->publishCarousel($postPlatform, $content),
            default => throw new PinterestPublishException(
                userMessage: "Unsupported content type: {$postPlatform->content_type->value}",
                category: ErrorCategory::ContentPolicy,
            ),
        };
    }

    private function publishImagePin(PostPlatform $postPlatform, ?string $content): array
    {
        $account = $postPlatform->socialAccount;
        $media = $postPlatform->post->mediaItems->first();

        if (! $media) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest requires at least one image',
                category: ErrorCategory::MediaFormat,
            );
        }

        $boardId = data_get($postPlatform->meta, 'board_id') ?? data_get($account->meta, 'default_board_id') ?? null;

        if (! $boardId) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest board_id is required',
                category: ErrorCategory::ContentPolicy,
            );
        }

        // Download and optimize image
        $tempFile = tempnam(sys_get_temp_dir(), 'pin_image_');

        try {
            $downloadResponse = Http::withOptions(['sink' => $tempFile])->timeout(600)->get($media->url);

            if ($downloadResponse->failed()) {
                throw new PinterestPublishException(
                    userMessage: 'Failed to download media: HTTP '.$downloadResponse->status(),
                    category: ErrorCategory::ServerError,
                );
            }

            $detectedMime = mime_content_type($tempFile) ?: '';
            if (MediaType::classify($detectedMime) === MediaType::Image && ! MediaType::isGif($detectedMime)) {
                $optimizer = app(MediaOptimizer::class);
                $optimizedPath = $optimizer->optimizeImage($tempFile, Platform::Pinterest);
                @unlink($tempFile);
                $tempFile = $optimizedPath;
            }

            $imageBase64 = base64_encode(file_get_contents($tempFile));
        } finally {
            @unlink($tempFile);
        }

        $payload = [
            'board_id' => $boardId,
            'media_source' => [
                'source_type' => 'image_base64',
                'content_type' => 'image/jpeg',
                'data' => $imageBase64,
            ],
        ];

        if ($content) {
            $payload['description'] = $content;
        }

        if (! empty(data_get($postPlatform->meta, 'title'))) {
            $payload['title'] = substr(data_get($postPlatform->meta, 'title'), 0, 100);
        }

        if (! empty(data_get($postPlatform->meta, 'link'))) {
            $payload['link'] = data_get($postPlatform->meta, 'link');
        }

        $alt = $postPlatform->post->mediaItems->first(fn ($m) => $m->isImage())?->altTextFor(Platform::Pinterest);

        if ($alt !== null) {
            $payload['alt_text'] = $alt;
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->post($this->baseUrl.'/pins', $payload);

        if ($response->failed()) {
            Log::error('Pinterest pin creation failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();

        return [
            'id' => data_get($data, 'id'),
            'url' => 'https://pinterest.com/pin/'.data_get($data, 'id'),
        ];
    }

    private function publishVideoPin(PostPlatform $postPlatform, ?string $content): array
    {
        $account = $postPlatform->socialAccount;
        $media = $postPlatform->post->mediaItems->first();

        if (! $media) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest requires a video',
                category: ErrorCategory::MediaFormat,
            );
        }

        $boardId = data_get($postPlatform->meta, 'board_id') ?? data_get($account->meta, 'default_board_id') ?? null;

        if (! $boardId) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest board_id is required',
                category: ErrorCategory::ContentPolicy,
            );
        }

        // Step 1: Register media upload
        $registerResponse = $this->socialHttp()->withToken($account->access_token)
            ->post($this->baseUrl.'/media', [
                'media_type' => 'video',
            ]);

        if ($registerResponse->failed()) {
            Log::error('Pinterest media registration failed', [
                'status' => $registerResponse->status(),
                'body' => $this->redactResponseBody($registerResponse->body()),
            ]);
            $this->handleApiError($registerResponse);
        }

        $registerData = $registerResponse->json();
        $mediaId = $registerData['media_id'] ?? null;

        if (! $mediaId) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest media registration failed: no media ID returned',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 2: Upload video to S3
        $uploadParams = $registerData['upload_parameters'] ?? [];
        $uploadUrl = $registerData['upload_url'] ?? null;

        if (! $uploadUrl) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest did not return upload URL',
                category: ErrorCategory::ServerError,
            );
        }

        // Build multipart form data
        $multipart = [];
        foreach ($uploadParams as $key => $value) {
            $multipart[] = ['name' => $key, 'contents' => $value];
        }

        // Download video to temp file (memory-safe)
        $tempFile = tempnam(sys_get_temp_dir(), 'pin_video_');
        $videoStream = null;

        try {
            $downloadResponse = Http::withOptions(['sink' => $tempFile])->timeout(600)->get($media->url);

            if ($downloadResponse->failed()) {
                throw new PinterestPublishException(
                    userMessage: 'Failed to download media: HTTP '.$downloadResponse->status(),
                    category: ErrorCategory::ServerError,
                );
            }

            $videoStream = fopen($tempFile, 'r');

            if ($videoStream === false) {
                throw new PinterestPublishException(
                    userMessage: 'Failed to read video file',
                    category: ErrorCategory::ServerError,
                );
            }

            $multipart[] = [
                'name' => 'file',
                'contents' => $videoStream,
                'filename' => basename($media->url),
            ];

            $uploadResponse = Http::asMultipart()
                ->timeout(600)
                ->post($uploadUrl, $multipart);

            if ($uploadResponse->failed()) {
                $this->handleApiError($uploadResponse);
            }
        } finally {
            if ($videoStream !== null && is_resource($videoStream)) {
                fclose($videoStream);
            }
            @unlink($tempFile);
        }

        // Step 3: Wait for processing
        $this->waitForMediaProcessing($account, $mediaId);

        // Step 4: Create pin with video
        $payload = [
            'board_id' => $boardId,
            'media_source' => [
                'source_type' => 'video_id',
                'media_id' => $mediaId,
            ],
        ];

        if ($content) {
            $payload['description'] = $content;
        }

        if (! empty(data_get($postPlatform->meta, 'title'))) {
            $payload['title'] = substr(data_get($postPlatform->meta, 'title'), 0, 100);
        }

        if (! empty(data_get($postPlatform->meta, 'link'))) {
            $payload['link'] = data_get($postPlatform->meta, 'link');
        }

        if (! empty(data_get($postPlatform->meta, 'cover_image_url'))) {
            $payload['media_source']['cover_image_url'] = data_get($postPlatform->meta, 'cover_image_url');
        } else {
            $payload['media_source']['cover_image_key_frame_time'] = 0;
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->post($this->baseUrl.'/pins', $payload);

        if ($response->failed()) {
            Log::error('Pinterest video pin creation failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();

        return [
            'id' => data_get($data, 'id'),
            'url' => 'https://pinterest.com/pin/'.data_get($data, 'id'),
        ];
    }

    private function publishCarousel(PostPlatform $postPlatform, ?string $content): array
    {
        $account = $postPlatform->socialAccount;
        $medias = $postPlatform->post->mediaItems;

        if ($medias->count() < 2 || $medias->count() > 5) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest carousel requires 2-5 images',
                category: ErrorCategory::MediaFormat,
            );
        }

        $boardId = data_get($postPlatform->meta, 'board_id') ?? data_get($account->meta, 'default_board_id') ?? null;

        if (! $boardId) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest board_id is required',
                category: ErrorCategory::ContentPolicy,
            );
        }

        $items = $medias->map(fn ($media) => [
            'url' => $media->url,
        ])->toArray();

        $payload = [
            'board_id' => $boardId,
            'media_source' => [
                'source_type' => 'multiple_image_urls',
                'items' => $items,
            ],
        ];

        if ($content) {
            $payload['description'] = $content;
        }

        if (! empty(data_get($postPlatform->meta, 'title'))) {
            $payload['title'] = substr(data_get($postPlatform->meta, 'title'), 0, 100);
        }

        if (! empty(data_get($postPlatform->meta, 'link'))) {
            $payload['link'] = data_get($postPlatform->meta, 'link');
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->post($this->baseUrl.'/pins', $payload);

        if ($response->failed()) {
            Log::error('Pinterest carousel creation failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();

        return [
            'id' => data_get($data, 'id'),
            'url' => 'https://pinterest.com/pin/'.data_get($data, 'id'),
        ];
    }

    private function waitForMediaProcessing(SocialAccount $account, string $mediaId, int $maxAttempts = 30): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->socialHttp()->withToken($account->access_token)
                ->get($this->baseUrl."/media/{$mediaId}");

            if ($response->failed()) {
                Log::warning('Pinterest media status check failed', [
                    'media_id' => $mediaId,
                    'attempt' => $i,
                    'body' => $this->redactResponseBody($response->body()),
                ]);
                sleep(3);

                continue;
            }

            $data = $response->json();
            $status = data_get($data, 'status', 'unknown');

            if ($status === 'succeeded') {
                return;
            }

            if ($status === 'failed') {
                $failureCode = data_get($data, 'failure_code', 'unknown');
                throw new PinterestPublishException(
                    userMessage: "Pinterest media processing failed: {$failureCode}",
                    category: ErrorCategory::ServerError,
                    platformErrorCode: (string) $failureCode,
                );
            }

            sleep(3);
        }

        throw new PinterestPublishException(
            userMessage: "Pinterest media processing timeout after {$maxAttempts} attempts",
            category: ErrorCategory::ServerError,
        );
    }

    /**
     * Get user's boards for board selection.
     */
    public function getBoards(SocialAccount $account): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->get($this->baseUrl.'/boards', [
                'page_size' => 100,
            ]);

        if ($response->failed()) {
            Log::error('Pinterest get boards failed', ['body' => $this->redactResponseBody($response->body())]);
            $this->handleApiError($response);
        }

        return $response->json()['items'] ?? [];
    }

    private function handleApiError(Response $response): never
    {
        throw PinterestPublishException::fromApiResponse($response);
    }
}

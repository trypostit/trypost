<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Media\Type as MediaType;
use App\Enums\Pinterest\MediaUploadStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\PinterestPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

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

        $content = $postPlatform->post->content
            ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform)
            : null;

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

    /**
     * Apply pin description from the shared post caption, plus optional title/link from meta.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyPinTextFields(array $payload, PostPlatform $postPlatform, ?string $content): array
    {
        if (filled($content)) {
            $payload['description'] = mb_substr($content, 0, 800);
        }

        if (filled(data_get($postPlatform->meta, 'title'))) {
            $payload['title'] = mb_substr((string) data_get($postPlatform->meta, 'title'), 0, 100);
        }

        if (filled(data_get($postPlatform->meta, 'link'))) {
            $payload['link'] = data_get($postPlatform->meta, 'link');
        }

        return $payload;
    }

    private function resolveBoardId(PostPlatform $postPlatform): string
    {
        $boardId = data_get($postPlatform->meta, 'board_id')
            ?? data_get($postPlatform->socialAccount->meta, 'default_board_id');

        if (! filled($boardId)) {
            throw new PinterestPublishException(
                userMessage: 'Pinterest board_id is required',
                category: ErrorCategory::ContentPolicy,
            );
        }

        return (string) $boardId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: mixed, url: string}
     */
    private function createPin(SocialAccount $account, array $payload, string $failureLogMessage): array
    {
        $response = $this->socialHttp()->withToken($account->access_token)
            ->post($this->baseUrl.'/pins', $payload);

        if ($response->failed()) {
            Log::error($failureLogMessage, [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $pinId = data_get($response->json(), 'id');

        return [
            'id' => $pinId,
            'url' => "https://pinterest.com/pin/{$pinId}",
        ];
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

        $boardId = $this->resolveBoardId($postPlatform);

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

        $payload = $this->applyPinTextFields([
            'board_id' => $boardId,
            'media_source' => [
                'source_type' => 'image_base64',
                'content_type' => 'image/jpeg',
                'data' => $imageBase64,
            ],
        ], $postPlatform, $content);

        $alt = $postPlatform->post->mediaItems->first(fn ($m) => $m->isImage())?->altTextFor(Platform::Pinterest);

        if ($alt !== null) {
            $payload['alt_text'] = $alt;
        }

        return $this->createPin($account, $payload, 'Pinterest pin creation failed');
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

        $boardId = $this->resolveBoardId($postPlatform);

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
        $payload = $this->applyPinTextFields([
            'board_id' => $boardId,
            'media_source' => [
                'source_type' => 'video_id',
                'media_id' => $mediaId,
            ],
        ], $postPlatform, $content);

        if (! empty(data_get($postPlatform->meta, 'cover_image_url'))) {
            $payload['media_source']['cover_image_url'] = data_get($postPlatform->meta, 'cover_image_url');
        } else {
            $payload['media_source']['cover_image_key_frame_time'] = 0;
        }

        return $this->createPin($account, $payload, 'Pinterest video pin creation failed');
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

        $items = $medias->map(fn ($media) => [
            'url' => $media->url,
        ])->toArray();

        $payload = $this->applyPinTextFields([
            'board_id' => $this->resolveBoardId($postPlatform),
            'media_source' => [
                'source_type' => 'multiple_image_urls',
                'items' => $items,
            ],
        ], $postPlatform, $content);

        return $this->createPin($account, $payload, 'Pinterest carousel creation failed');
    }

    /** @throws PlatformUnavailableException|PinterestPublishException */
    private function waitForMediaProcessing(SocialAccount $account, string $mediaId): void
    {
        $maxAttempts = 60;
        $pollSeconds = 5;
        $lastStatus = null;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if ($i > 0) {
                Sleep::for($pollSeconds)->seconds();
            }

            try {
                $response = $this->socialHttp()->withToken($account->access_token)
                    ->get($this->baseUrl."/media/{$mediaId}");
            } catch (ConnectionException $e) {
                throw new PlatformUnavailableException(
                    "Pinterest media status check connection failed (media_id={$mediaId}): {$e->getMessage()}",
                );
            }

            if ($response->failed()) {
                if ($response->status() === 401) {
                    $this->handleApiError($response);
                }

                Log::warning('Pinterest media status check failed', [
                    'media_id' => $mediaId,
                    'attempt' => $i + 1,
                    'status_code' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                continue;
            }

            $data = $response->json();
            $lastStatus = MediaUploadStatus::tryFrom((string) data_get($data, 'status', ''));

            if ($lastStatus === MediaUploadStatus::Succeeded) {
                return;
            }

            if ($lastStatus === MediaUploadStatus::Failed) {
                $failureCode = data_get($data, 'failure_code', 'unknown');
                throw new PinterestPublishException(
                    userMessage: "Pinterest media processing failed: {$failureCode}",
                    category: ErrorCategory::ServerError,
                    platformErrorCode: (string) $failureCode,
                    rawResponse: $response->body(),
                );
            }
        }

        Log::warning('Pinterest media processing timeout', [
            'media_id' => $mediaId,
            'attempts' => $maxAttempts,
            'last_status' => $lastStatus?->value,
            'poll_seconds' => $pollSeconds,
        ]);

        throw new PlatformUnavailableException(
            "Pinterest media processing timeout after {$maxAttempts} attempts (media_id={$mediaId}, last_status=".($lastStatus?->value ?? 'unknown').')',
        );
    }

    /**
     * Get user's boards for board selection (follows Pinterest bookmark pagination
     * until the API stops returning a bookmark).
     *
     * @return array{boards: list<array<string, mixed>>, truncated: bool}
     */
    public function getBoards(SocialAccount $account): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $boards = [];
        $bookmark = null;
        $pages = 0;
        $truncated = false;
        // Absurd ceiling only — normal accounts exit when bookmark is blank.
        $maxPages = 1000;

        while (true) {
            $pages++;

            if ($pages > $maxPages) {
                Log::warning('Pinterest get boards hit safety page cap', [
                    'account_id' => $account->id,
                    'pages' => $pages,
                    'boards' => count($boards),
                ]);
                $truncated = true;

                break;
            }

            $query = ['page_size' => 100];

            if (filled($bookmark)) {
                $query['bookmark'] = $bookmark;
            }

            $response = $this->socialHttp()->withToken($account->access_token)
                ->get($this->baseUrl.'/boards', $query);

            if ($response->failed()) {
                Log::error('Pinterest get boards failed', ['body' => $this->redactResponseBody($response->body())]);
                $this->handleApiError($response);
            }

            $payload = $response->json() ?? [];
            $items = data_get($payload, 'items', []);

            if (is_array($items) && $items !== []) {
                array_push($boards, ...$items);
            }

            $nextBookmark = data_get($payload, 'bookmark');

            if (blank($nextBookmark)) {
                break;
            }

            if ($nextBookmark === $bookmark) {
                Log::warning('Pinterest get boards returned a repeated bookmark', [
                    'account_id' => $account->id,
                    'pages' => $pages,
                    'boards' => count($boards),
                ]);
                $truncated = true;

                break;
            }

            $bookmark = $nextBookmark;
        }

        return [
            'boards' => $boards,
            'truncated' => $truncated,
        ];
    }

    private function handleApiError(Response $response): never
    {
        throw PinterestPublishException::fromApiResponse($response);
    }
}

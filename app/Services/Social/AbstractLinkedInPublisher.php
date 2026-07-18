<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\LinkedInPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared LinkedIn publishing pipeline. The publish format follows the attached
 * media — a PDF becomes a document post, 2+ images a multi-image post, and
 * anything else (a single image/video or text only) a regular post. Subclasses
 * provide the author identity (member vs. company page) and its public URL.
 */
abstract class AbstractLinkedInPublisher
{
    use HasSocialHttpClient;

    private string $apiVersion = '202601';

    private string $accessToken;

    protected SocialAccount $account;

    private bool $hasRetried = false;

    /**
     * The LinkedIn account kind this publisher targets (member or company page).
     */
    abstract protected function platform(): Platform;

    /**
     * The URN that authors the post and owns its uploaded media.
     */
    abstract protected function authorUrn(): string;

    /**
     * Public URL of the created post. Defaults to the member feed update;
     * company pages override it.
     */
    protected function postUrl(?string $postId): ?string
    {
        return $postId ? "https://www.linkedin.com/feed/update/{$postId}" : null;
    }

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content
            ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform)
            : null;

        $this->account = $postPlatform->socialAccount;
        $this->hasRetried = false;

        if ($this->account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($this->account);
        }

        $this->accessToken = $this->account->access_token;

        try {
            return $this->dispatchByMedia($content, $postPlatform);
        } catch (TokenExpiredException $e) {
            return $this->retryWithRefresh($postPlatform, $content, $e);
        }
    }

    private function dispatchByMedia(?string $content, PostPlatform $postPlatform): array
    {
        $media = $postPlatform->post->mediaItems;

        if ($media->contains(fn ($item) => $item->isDocument())) {
            return $this->publishDocument($content, $media, $this->resolveDocumentTitle($postPlatform));
        }

        if ($media->filter(fn ($item) => $item->isImage())->count() >= 2) {
            return $this->publishCarousel($content, $media);
        }

        return $this->publishPost($content, $media);
    }

    private function retryWithRefresh(PostPlatform $postPlatform, ?string $content, TokenExpiredException $originalException): array
    {
        if ($this->hasRetried) {
            throw $originalException;
        }

        $this->hasRetried = true;

        try {
            app(ConnectionVerifier::class)->refreshToken($this->account);
            $this->accessToken = $this->account->access_token;

            return $this->dispatchByMedia($content, $postPlatform);
        } catch (\Throwable $e) {
            Log::error("{$this->label()} refresh failed during retry", [
                'account_id' => $this->account->id,
                'error' => $e->getMessage(),
            ]);
            throw $originalException;
        }
    }

    private function publishPost(?string $content, $media): array
    {
        $payload = $this->basePayload($content);

        if ($media->isNotEmpty()) {
            $item = $media->first();
            $mediaUrn = $this->uploadMedia($item);

            if ($mediaUrn) {
                $payload['content'] = ['media' => array_filter([
                    'id' => $mediaUrn,
                    'altText' => $item->isImage() ? $item->altTextFor($this->platform()) : null,
                ], fn ($v) => $v !== null)];
            }
        }

        return $this->createPost($payload, 'post creation');
    }

    private function publishCarousel(?string $content, $media): array
    {
        $images = [];

        $imageItems = collect($media)
            ->filter(fn ($item) => $item->isImage())
            ->take($this->platform()->maxImages());

        foreach ($imageItems as $item) {
            $imageUrn = $this->uploadImage($item);

            if ($imageUrn) {
                $images[] = array_filter([
                    'id' => $imageUrn,
                    'altText' => $item->altTextFor($this->platform()),
                ], fn ($v) => $v !== null);
            }
        }

        $payload = $this->basePayload($content);
        $payload['content'] = ['multiImage' => ['images' => $images]];

        return $this->createPost($payload, 'carousel post creation');
    }

    private function publishDocument(?string $content, $media, string $title): array
    {
        $document = $media->first(fn ($item) => $item->isDocument());

        if (! $document) {
            throw new LinkedInPublishException(
                userMessage: "No PDF document was found for this {$this->label()} document post.",
                category: ErrorCategory::MediaFormat,
            );
        }

        $documentUrn = $this->uploadDocument($document);

        if (! $documentUrn) {
            throw new LinkedInPublishException(
                userMessage: "{$this->label()} did not accept the document. Please try again.",
                category: ErrorCategory::ServerError,
            );
        }

        $payload = $this->basePayload($content);
        $payload['content'] = ['media' => ['id' => $documentUrn, 'title' => $title]];

        return $this->createPost($payload, 'document post creation');
    }

    /**
     * The shared post envelope shared by every content shape.
     *
     * @return array<string, mixed>
     */
    private function basePayload(?string $content): array
    {
        return [
            'author' => $this->authorUrn(),
            'commentary' => $content ?? '',
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
        ];
    }

    /**
     * POST the assembled payload to the Posts API and return the created post.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string, url: ?string}
     */
    private function createPost(array $payload, string $context): array
    {
        $response = $this->getHttpClient()->post("{$this->baseUrl()}/rest/posts", $payload);

        if ($response->failed()) {
            Log::error("{$this->label()} {$context} failed", [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $postId = $response->header('x-restli-id');

        return [
            'id' => $postId ?? 'unknown',
            'url' => $this->postUrl($postId ?: null),
        ];
    }

    /**
     * The title shown on a LinkedIn document (PDF carousel) post. Falls back to
     * the uploaded file name, then a generic label, so it's never empty.
     */
    private function resolveDocumentTitle(PostPlatform $postPlatform): string
    {
        $title = data_get($postPlatform->meta, 'document_title');

        if (filled($title)) {
            return (string) $title;
        }

        return $postPlatform->post->mediaItems->first(fn ($media) => $media->isDocument())?->original_filename ?? 'Document';
    }

    private function uploadMedia($mediaItem): ?string
    {
        return match (true) {
            $mediaItem->isVideo() => $this->uploadVideo($mediaItem),
            $mediaItem->isImage() => $this->uploadImage($mediaItem),
            default => null,
        };
    }

    private function uploadImage($mediaItem): ?string
    {
        $initResponse = $this->getHttpClient()
            ->post("{$this->baseUrl()}/rest/images?action=initializeUpload", [
                'initializeUploadRequest' => ['owner' => $this->authorUrn()],
            ]);

        if ($initResponse->failed()) {
            Log::error("{$this->label()} image init failed", ['body' => $this->redactResponseBody($initResponse->body())]);
            $this->handleApiError($initResponse);
        }

        $initData = $initResponse->json();
        $uploadUrl = data_get($initData, 'value.uploadUrl');
        $imageUrn = data_get($initData, 'value.image');

        if (! $uploadUrl || ! $imageUrn) {
            throw new LinkedInPublishException(
                userMessage: "{$this->label()} did not accept the image upload. Please try again.",
                category: ErrorCategory::ServerError,
            );
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'li_image_');

        try {
            $this->downloadToTempFile($mediaItem->url, $tempFile);

            $detectedMime = mime_content_type($tempFile) ?: '';
            if (MediaType::classify($detectedMime) === MediaType::Image && ! MediaType::isGif($detectedMime)) {
                $optimizedPath = app(MediaOptimizer::class)->optimizeImage($tempFile, $this->platform());
                @unlink($tempFile);
                $tempFile = $optimizedPath;
            }

            $stream = fopen($tempFile, 'r');

            $uploadResponse = Http::withToken($this->accessToken)
                ->withHeaders(['Content-Type' => 'application/octet-stream'])
                ->withBody($stream, 'application/octet-stream')
                ->put($uploadUrl);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($uploadResponse->failed()) {
                Log::error("{$this->label()} image upload failed", ['body' => $this->redactResponseBody($uploadResponse->body())]);
                $this->handleApiError($uploadResponse);
            }

            return $imageUrn;
        } finally {
            @unlink($tempFile);
        }
    }

    private function uploadVideo($mediaItem): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'li_video_');

        try {
            return $this->doUploadVideo($tempFile, $mediaItem);
        } finally {
            @unlink($tempFile);
        }
    }

    private function doUploadVideo(string $tempFile, $mediaItem): ?string
    {
        $this->downloadToTempFile($mediaItem->url, $tempFile);

        $fileSize = (int) filesize($tempFile);

        $initResponse = $this->getHttpClient()
            ->post("{$this->baseUrl()}/rest/videos?action=initializeUpload", [
                'initializeUploadRequest' => [
                    'owner' => $this->authorUrn(),
                    'fileSizeBytes' => $fileSize,
                    'uploadCaptions' => false,
                    'uploadThumbnail' => false,
                ],
            ]);

        if ($initResponse->failed()) {
            Log::error("{$this->label()} video init failed", ['body' => $this->redactResponseBody($initResponse->body())]);
            $this->handleApiError($initResponse);
        }

        $initData = $initResponse->json();
        $videoUrn = data_get($initData, 'value.video');
        $uploadInstructions = data_get($initData, 'value.uploadInstructions', []);
        $uploadToken = data_get($initData, 'value.uploadToken', '');

        if (! $videoUrn || empty($uploadInstructions)) {
            throw new LinkedInPublishException(
                userMessage: "{$this->label()} did not accept the video upload. Please try again.",
                category: ErrorCategory::ServerError,
            );
        }

        $uploadedPartIds = [];
        $handle = fopen($tempFile, 'r');

        try {
            foreach ($uploadInstructions as $index => $instruction) {
                $firstByte = data_get($instruction, 'firstByte');
                $lastByte = data_get($instruction, 'lastByte');

                $chunkLength = $lastByte - $firstByte + 1;
                fseek($handle, $firstByte);
                $chunkData = fread($handle, $chunkLength);

                $chunkResponse = Http::withToken($this->accessToken)
                    ->withHeaders(['Content-Type' => 'application/octet-stream'])
                    ->timeout(600)
                    ->withBody($chunkData, 'application/octet-stream')
                    ->put(data_get($instruction, 'uploadUrl'));

                if ($chunkResponse->failed()) {
                    Log::error("{$this->label()} video chunk upload failed", [
                        'index' => $index,
                        'body' => $this->redactResponseBody($chunkResponse->body()),
                    ]);
                    $this->handleApiError($chunkResponse);
                }

                $etag = $chunkResponse->header('etag');
                if ($etag) {
                    $uploadedPartIds[] = $etag;
                }

                unset($chunkData, $chunkResponse);
                $this->freeChunkMemory();
            }
        } finally {
            fclose($handle);
        }

        $finalizeResponse = $this->getHttpClient()
            ->post("{$this->baseUrl()}/rest/videos?action=finalizeUpload", [
                'finalizeUploadRequest' => [
                    'video' => $videoUrn,
                    'uploadToken' => $uploadToken,
                    'uploadedPartIds' => $uploadedPartIds,
                ],
            ]);

        if ($finalizeResponse->failed()) {
            Log::error("{$this->label()} video finalize failed", ['body' => $this->redactResponseBody($finalizeResponse->body())]);
            $this->handleApiError($finalizeResponse);
        }

        $this->waitForProcessing('videos', $videoUrn, 'video');

        return $videoUrn;
    }

    private function uploadDocument($mediaItem): ?string
    {
        $initResponse = $this->getHttpClient()
            ->post("{$this->baseUrl()}/rest/documents?action=initializeUpload", [
                'initializeUploadRequest' => ['owner' => $this->authorUrn()],
            ]);

        if ($initResponse->failed()) {
            Log::error("{$this->label()} document init failed", ['body' => $this->redactResponseBody($initResponse->body())]);
            $this->handleApiError($initResponse);
        }

        $initData = $initResponse->json();
        $uploadUrl = data_get($initData, 'value.uploadUrl');
        $documentUrn = data_get($initData, 'value.document');

        if (! $uploadUrl || ! $documentUrn) {
            throw new LinkedInPublishException(
                userMessage: "{$this->label()} did not accept the document upload. Please try again.",
                category: ErrorCategory::ServerError,
            );
        }

        // The Documents API is not chunked — upload the PDF in a single request.
        $tempFile = tempnam(sys_get_temp_dir(), 'li_document_');

        try {
            $this->downloadToTempFile($mediaItem->url, $tempFile);

            $stream = fopen($tempFile, 'r');

            $uploadResponse = Http::withToken($this->accessToken)
                ->withHeaders(['Content-Type' => 'application/pdf'])
                ->timeout(600)
                ->withBody($stream, 'application/pdf')
                ->put($uploadUrl);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($uploadResponse->failed()) {
                Log::error("{$this->label()} document upload failed", ['body' => $this->redactResponseBody($uploadResponse->body())]);
                $this->handleApiError($uploadResponse);
            }
        } finally {
            @unlink($tempFile);
        }

        $this->waitForProcessing('documents', $documentUrn, 'document');

        return $documentUrn;
    }

    /**
     * Poll an asset until it finishes processing. LinkedIn rejects a post that
     * references an asset still being processed, so we wait for AVAILABLE and
     * fail loudly if it never gets there — publishing against an unprocessed
     * asset would error out at the API anyway, with a far less obvious message.
     */
    private function waitForProcessing(string $resource, string $assetUrn, string $label): void
    {
        $encodedUrn = urlencode($assetUrn);

        for ($i = 0; $i < $this->processingMaxAttempts(); $i++) {
            $response = $this->getHttpClient()->get("{$this->baseUrl()}/rest/{$resource}/{$encodedUrn}");

            if ($response->failed()) {
                Log::warning("{$this->label()} {$label} status check failed", ['attempt' => $i]);
                sleep($this->processingPollSeconds());

                continue;
            }

            $status = data_get($response->json(), 'status', 'UNKNOWN');

            if ($status === 'AVAILABLE') {
                return;
            }

            if ($status === 'PROCESSING_FAILED') {
                throw new LinkedInPublishException(
                    userMessage: "{$this->label()} {$label} processing failed.",
                    category: ErrorCategory::ServerError,
                );
            }

            sleep($this->processingPollSeconds());
        }

        throw new LinkedInPublishException(
            userMessage: "{$this->label()} {$label} processing did not complete in time.",
            category: ErrorCategory::ServerError,
        );
    }

    /**
     * How many times to poll an uploaded asset for AVAILABLE before giving up.
     */
    protected function processingMaxAttempts(): int
    {
        return 30;
    }

    /**
     * Seconds to wait between asset processing status checks.
     */
    protected function processingPollSeconds(): int
    {
        return 5;
    }

    private function downloadToTempFile(string $url, string $tempFile): void
    {
        $response = Http::withOptions(['sink' => $tempFile])->timeout(600)->get($url);

        if ($response->failed()) {
            throw new LinkedInPublishException(
                userMessage: "Could not fetch the media to upload to {$this->label()}. Please try again.",
                category: ErrorCategory::ServerError,
            );
        }
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->withToken($this->accessToken)
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => $this->apiVersion,
                'Content-Type' => 'application/json',
            ]);
    }

    private function baseUrl(): string
    {
        return config("trypost.platforms.{$this->platform()->value}.api");
    }

    private function label(): string
    {
        return $this->platform()->label();
    }

    private function handleApiError(Response $response): never
    {
        throw LinkedInPublishException::fromApiResponse($response);
    }
}

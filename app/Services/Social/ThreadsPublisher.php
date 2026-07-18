<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\ThreadsPublishException;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class ThreadsPublisher
{
    use HasSocialHttpClient;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.threads.graph_api');
    }

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $account = $postPlatform->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $userId = $account->platform_user_id;
        $accessToken = $account->access_token;

        $media = $postPlatform->post->mediaItems;

        // Text only post
        if ($media->isEmpty()) {
            if (empty($content)) {
                throw new ThreadsPublishException(
                    userMessage: 'Threads text posts require content. Please add text to your post.',
                    category: ErrorCategory::MediaFormat,
                );
            }

            return $this->publishTextPost($userId, $accessToken, $content);
        }

        $firstMedia = $media->first();
        $isVideo = $firstMedia->isVideo();

        // Single media
        if ($media->count() === 1) {
            if ($isVideo) {
                return $this->publishVideoPost($userId, $accessToken, $content, $firstMedia);
            }

            return $this->publishImagePost($userId, $accessToken, $content, $firstMedia);
        }

        // Multiple media - carousel
        return $this->publishCarousel($userId, $accessToken, $content, $media);
    }

    private function publishTextPost(string $userId, string $accessToken, string $content): array
    {
        // Step 1: Create container
        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
            'media_type' => 'TEXT',
            'text' => $content,
            'access_token' => $accessToken,
        ]);

        if ($containerResponse->failed()) {
            Log::error('Threads container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the post. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 2: Publish
        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    private function publishImagePost(string $userId, string $accessToken, ?string $content, $media): array
    {
        // Step 1: Create container
        $params = [
            'media_type' => 'IMAGE',
            'image_url' => $media->url,
            'text' => $content,
            'access_token' => $accessToken,
        ];

        $alt = $media->altTextFor(Platform::Threads);

        if ($alt !== null) {
            $params['alt_text'] = $alt;
        }

        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", $params);

        if ($containerResponse->failed()) {
            Log::error('Threads image container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the image. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 2: Wait for image processing
        $this->waitForMediaProcessing($containerId, $accessToken);

        // Step 3: Publish
        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    private function publishVideoPost(string $userId, string $accessToken, ?string $content, $media): array
    {
        // Step 1: Create container
        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
            'media_type' => 'VIDEO',
            'video_url' => $media->url,
            'text' => $content,
            'access_token' => $accessToken,
        ]);

        if ($containerResponse->failed()) {
            Log::error('Threads video container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the video. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        // Wait for video processing
        $this->waitForMediaProcessing($containerId, $accessToken);

        // Step 2: Publish
        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    private function publishCarousel(string $userId, string $accessToken, ?string $content, $mediaCollection): array
    {
        // Step 1: Create containers for each media item
        $childContainers = [];

        foreach ($mediaCollection as $media) {
            $isVideo = $media->isVideo();

            $params = [
                'is_carousel_item' => 'true',
                'access_token' => $accessToken,
            ];

            if ($isVideo) {
                $params['media_type'] = 'VIDEO';
                $params['video_url'] = $media->url;
            } else {
                $params['media_type'] = 'IMAGE';
                $params['image_url'] = $media->url;

                $alt = $media->altTextFor(Platform::Threads);

                if ($alt !== null) {
                    $params['alt_text'] = $alt;
                }
            }

            $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", $params);

            if ($containerResponse->failed()) {
                Log::error('Threads carousel item creation failed', [
                    'body' => $this->redactResponseBody($containerResponse->body()),
                ]);

                continue;
            }

            $childId = $containerResponse->json()['id'] ?? null;

            if (! $childId) {
                Log::error('Threads carousel item creation returned no ID', ['body' => $this->redactResponseBody($containerResponse->body())]);

                continue;
            }

            // Wait for media processing (both images and videos)
            $this->waitForMediaProcessing($childId, $accessToken);

            $childContainers[] = $childId;
        }

        if (empty($childContainers)) {
            throw new ThreadsPublishException(
                userMessage: 'Failed to create any carousel items',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 2: Create carousel container
        $carouselResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
            'media_type' => 'CAROUSEL',
            'text' => $content,
            'children' => implode(',', $childContainers),
            'access_token' => $accessToken,
        ]);

        if ($carouselResponse->failed()) {
            Log::error('Threads carousel container creation failed', [
                'body' => $this->redactResponseBody($carouselResponse->body()),
            ]);
            $this->handleApiError($carouselResponse);
        }

        $carouselId = $carouselResponse->json()['id'] ?? null;

        if (! $carouselId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the carousel. Please try again.',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 3: Publish carousel
        return $this->publishContainer($userId, $accessToken, $carouselId);
    }

    private function publishContainer(string $userId, string $accessToken, string $containerId): array
    {
        $publishResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads_publish", [
            'creation_id' => $containerId,
            'access_token' => $accessToken,
        ]);

        if ($publishResponse->failed()) {
            Log::error('Threads publish failed', [
                'status' => $publishResponse->status(),
                'body' => $this->redactResponseBody($publishResponse->body()),
            ]);
            $this->handleApiError($publishResponse);
        }

        $mediaId = $publishResponse->json()['id'] ?? null;

        if (! $mediaId) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the post. Please publish again.',
                category: ErrorCategory::ServerError,
            );
        }

        // Get permalink
        $permalinkResponse = $this->socialHttp()->get("{$this->baseUrl}/{$mediaId}", [
            'fields' => 'permalink',
            'access_token' => $accessToken,
        ]);

        $permalink = $permalinkResponse->json()['permalink'] ?? null;

        return [
            'id' => $mediaId,
            'url' => $permalink,
        ];
    }

    private function waitForMediaProcessing(string $containerId, string $accessToken, int $maxAttempts = 30): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $statusResponse = $this->socialHttp()->get("{$this->baseUrl}/{$containerId}", [
                'fields' => 'status,error_message',
                'access_token' => $accessToken,
            ]);

            if ($statusResponse->failed()) {
                Log::warning('Threads status check failed', [
                    'container_id' => $containerId,
                    'attempt' => $i,
                    'body' => $this->redactResponseBody($statusResponse->body()),
                ]);
                sleep(3);

                continue;
            }

            $data = $statusResponse->json();
            $status = data_get($data, 'status', 'UNKNOWN');

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR') {
                $errorMessage = data_get($data, 'error_message', 'Unknown error');
                throw new ThreadsPublishException(
                    userMessage: 'Threads media processing failed. Please try a different file.',
                    category: ErrorCategory::ServerError,
                );
            }

            sleep(3);
        }

        Log::warning('Threads media processing timeout', ['container_id' => $containerId]);
        throw new ThreadsPublishException(
            userMessage: 'Threads took too long to process the media. Please try again.',
            category: ErrorCategory::ServerError,
        );
    }

    private function handleApiError(Response $response): never
    {
        throw ThreadsPublishException::fromApiResponse($response);
    }
}

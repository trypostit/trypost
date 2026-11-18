<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\ThreadsMediaContainerNotFoundException;
use App\Exceptions\Social\ThreadsPublishException;
use App\Models\Post;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Support\PostPlatformMetaRules;
use App\Support\Social\ThreadsPoll;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class ThreadsPublisher
{
    use HasSocialHttpClient;

    private const int MEDIA_PUBLICATION_MAX_ATTEMPTS = 3;

    private const int MEDIA_PROCESSING_POLL_SECONDS = 3;

    private const int MEDIA_READY_GRACE_SECONDS = 2;

    private string $baseUrl;

    /**
     * Extra parameters for the post's own container (never carousel children).
     *
     * @var array<string, mixed>
     */
    private array $containerOptions = [];

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.threads.graph_api');
    }

    public function publish(Post $post): array
    {
        $this->validateContentLength($post);

        $content = app(ContentSanitizer::class)->forPost($post);

        $account = $post->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $userId = $account->platform_user_id;
        $accessToken = $account->access_token;

        $media = $post->mediaItems;

        if ($post->content_type === ContentType::ThreadsGhostPost && $media->isNotEmpty()) {
            throw new ThreadsPublishException(
                userMessage: __('posts.form.warnings.text_only'),
                category: ErrorCategory::MediaFormat,
            );
        }

        $this->containerOptions = $this->containerOptions($post, $content);

        // Text only post
        if ($media->isEmpty()) {
            if (empty($content)) {
                throw new ThreadsPublishException(
                    userMessage: 'Threads text posts require content. Please add text to your post.',
                    category: ErrorCategory::MediaFormat,
                );
            }

            return $this->publishMediaWithRetry(
                fn (): array => $this->publishTextPost($userId, $accessToken, $content),
            );
        }

        if ($media->count() === 1) {
            return $this->publishMediaWithRetry(
                fn (): array => $this->publishSingleMedia($userId, $accessToken, $content, $media->first()),
            );
        }

        // Multiple media - carousel
        return $this->publishMediaWithRetry(
            fn (): array => $this->publishCarousel($userId, $accessToken, $content, $media),
        );
    }

    private function publishTextPost(string $userId, string $accessToken, string $content): array
    {
        // Step 1: Create container
        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
            'media_type' => 'TEXT',
            'text' => $content,
            ...$this->containerOptions,
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

        $this->waitForMediaProcessing($containerId, $accessToken);

        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    private function publishSingleMedia(string $userId, string $accessToken, ?string $content, MediaItem $media): array
    {
        $kind = $media->isVideo() ? 'video' : 'image';

        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
            ...$this->mediaParams($media),
            'text' => $content,
            ...$this->containerOptions,
            'access_token' => $accessToken,
        ]);

        if ($containerResponse->failed()) {
            Log::error("Threads {$kind} container creation failed", [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new ThreadsPublishException(
                userMessage: "Threads did not accept the {$kind}. Please try again.",
                category: ErrorCategory::ServerError,
            );
        }

        $this->waitForMediaProcessing($containerId, $accessToken);

        return $this->publishContainer($userId, $accessToken, $containerId);
    }

    /**
     * The container fields of one image or video: its type, its URL and its
     * alt text, which Threads takes on both.
     *
     * @return array<string, string>
     */
    private function mediaParams(MediaItem $media): array
    {
        $params = $this->mediaSource($media);
        $alt = $media->altTextFor(Platform::Threads);

        if (blank($alt)) {
            return $params;
        }

        return [...$params, 'alt_text' => $alt];
    }

    /**
     * @return array{media_type: string, video_url?: string, image_url?: string}
     */
    private function mediaSource(MediaItem $media): array
    {
        if ($media->isVideo()) {
            return ['media_type' => 'VIDEO', 'video_url' => $media->url];
        }

        return ['media_type' => 'IMAGE', 'image_url' => $media->url];
    }

    private function publishCarousel(string $userId, string $accessToken, ?string $content, $mediaCollection): array
    {
        // Step 1: Create containers for each media item
        $childContainers = [];

        foreach ($mediaCollection as $media) {
            $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads", [
                ...$this->mediaParams($media),
                'is_carousel_item' => 'true',
                'access_token' => $accessToken,
            ]);

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
            ...$this->containerOptions,
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

        $this->waitForMediaProcessing($carouselId, $accessToken);

        // Step 3: Publish carousel
        return $this->publishContainer($userId, $accessToken, $carouselId);
    }

    /**
     * @param  callable(): array{id: string, url: ?string}  $publish
     * @return array{id: string, url: ?string}
     */
    private function publishMediaWithRetry(callable $publish): array
    {
        for ($attempt = 1; $attempt <= self::MEDIA_PUBLICATION_MAX_ATTEMPTS; $attempt++) {
            try {
                return $publish();
            } catch (ThreadsMediaContainerNotFoundException $exception) {
                if ($attempt === self::MEDIA_PUBLICATION_MAX_ATTEMPTS) {
                    throw $exception;
                }

                Log::warning('Threads media container was not found; recreating publication flow', [
                    'attempt' => $attempt,
                    'max_attempts' => self::MEDIA_PUBLICATION_MAX_ATTEMPTS,
                    ...$exception->context(),
                ]);
            }
        }

        throw new ThreadsPublishException(
            userMessage: 'Threads did not accept the post. Please publish again.',
            category: ErrorCategory::ServerError,
        );
    }

    private function publishContainer(string $userId, string $accessToken, string $containerId): array
    {
        $publishResponse = $this->socialHttp()->post("{$this->baseUrl}/{$userId}/threads_publish", [
            'creation_id' => $containerId,
            'access_token' => $accessToken,
        ]);

        if ($publishResponse->failed()) {
            if (ThreadsMediaContainerNotFoundException::matches($publishResponse)) {
                throw ThreadsMediaContainerNotFoundException::fromApiResponse($publishResponse);
            }

            Log::error('Threads publish failed', [
                'status' => $publishResponse->status(),
                'body' => $this->redactResponseBody($publishResponse->body()),
            ]);

            $this->handleApiError($publishResponse);
        }

        $mediaId = (string) $publishResponse->json('id');

        if (blank($mediaId)) {
            throw new ThreadsPublishException(
                userMessage: 'Threads did not accept the post. Please publish again.',
                category: ErrorCategory::ServerError,
            );
        }

        try {
            $permalinkResponse = $this->socialHttp()->get("{$this->baseUrl}/{$mediaId}", [
                'fields' => 'permalink',
                'access_token' => $accessToken,
            ]);
        } catch (ConnectionException) {
            return [
                'id' => $mediaId,
                'url' => null,
            ];
        }

        $permalink = $permalinkResponse->json('permalink');

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
                Sleep::for(self::MEDIA_PROCESSING_POLL_SECONDS)->seconds();

                continue;
            }

            $data = $statusResponse->json();
            $status = data_get($data, 'status', 'UNKNOWN');

            if ($status === 'FINISHED') {
                Sleep::for(self::MEDIA_READY_GRACE_SECONDS)->seconds();

                return;
            }

            if ($status === 'ERROR') {
                $errorMessage = data_get($data, 'error_message', 'Unknown error');
                throw new ThreadsPublishException(
                    userMessage: 'Threads media processing failed. Please try a different file.',
                    category: ErrorCategory::ServerError,
                );
            }

            Sleep::for(self::MEDIA_PROCESSING_POLL_SECONDS)->seconds();
        }

        Log::warning('Threads media processing timeout', ['container_id' => $containerId]);
        throw new ThreadsPublishException(
            userMessage: 'Threads took too long to process the media. Please try again.',
            category: ErrorCategory::ServerError,
        );
    }

    /**
     * What the container takes besides the text and media: a ghost post is
     * marked as one, a poll carries its choices, and a post its topic.
     *
     * @return array<string, mixed>
     */
    private function containerOptions(Post $post, ?string $content): array
    {
        if ($post->content_type === ContentType::ThreadsGhostPost) {
            return ['is_ghost_post' => 'true'];
        }

        if ($post->content_type === ContentType::ThreadsPoll) {
            return [...$this->postOptions($post), 'poll_attachment' => $this->pollAttachment($post, $content)];
        }

        return $this->postOptions($post);
    }

    /**
     * A poll goes on a text post only, with its question and choices, sent as
     * a JSON string as the Threads docs show it (like Instagram's nested
     * parameters).
     */
    private function pollAttachment(Post $post, ?string $content): string
    {
        $violation = ThreadsPoll::publishViolation($content, $post->mediaItems->isNotEmpty(), $post->meta);

        if (filled($violation)) {
            throw new ThreadsPublishException(userMessage: $violation, category: ErrorCategory::ContentPolicy);
        }

        return (string) json_encode(ThreadsPoll::payload($post->meta));
    }

    /**
     * @return array<string, string>
     */
    private function postOptions(Post $post): array
    {
        $topicTag = PostPlatformMetaRules::threadsTopicTag((string) data_get($post->meta, 'topic_tag'));

        return $topicTag === '' ? [] : ['topic_tag' => $topicTag];
    }

    private function handleApiError(Response $response): never
    {
        throw ThreadsPublishException::fromApiResponse($response);
    }
}

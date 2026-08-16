<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Instagram\ContainerStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\CropsImageForAspectRatio;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\Meta\GraphError;
use App\Support\Social\PublishCheckpoint;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class InstagramPublisher
{
    use CropsImageForAspectRatio;
    use HasSocialHttpClient;

    private string $baseUrl;

    private PostPlatform $postPlatform;

    private const int STATUS_RETRY_DELAY_SECONDS = 10;

    private const int STATUS_MAX_RETRIES = 90;

    private const string WORKFLOW_CAROUSEL_CHILDREN = 'carousel_children';

    private const string WORKFLOW_FINAL_CONTAINER = 'final_container';

    public function publish(PostPlatform $postPlatform): array
    {
        $this->postPlatform = $postPlatform;
        $this->validateContentLength($postPlatform);

        $account = $postPlatform->socialAccount;
        $this->baseUrl = $account->platform->instagramGraphBaseUrl();

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $instagramId = $account->platform_user_id;
        $accessToken = $account->access_token;

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $pendingWorkflow = PublishCheckpoint::instagramWorkflow($postPlatform->error_context);

        if ($pendingWorkflow !== null) {
            return $this->resumeWorkflow($instagramId, $accessToken, $content, $pendingWorkflow);
        }

        $media = $postPlatform->post->mediaItems;

        if ($media->isEmpty()) {
            throw new InstagramPublishException(
                userMessage: 'Instagram requires at least one image or video.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $firstMedia = $media->first();
        $contentType = $postPlatform->content_type;

        $aspectRatio = data_get($postPlatform->meta, 'aspect_ratio');

        return match ($contentType) {
            ContentType::InstagramReel => $this->publishReel($instagramId, $accessToken, $content, $firstMedia),
            ContentType::InstagramStory => $this->publishStory($instagramId, $accessToken, $firstMedia),
            ContentType::InstagramFeed => $this->publishFeed($instagramId, $accessToken, $content, $media, $aspectRatio),
            default => throw new InstagramPublishException(
                userMessage: "Unsupported Instagram content type: {$contentType?->value}",
                category: ErrorCategory::ContentPolicy,
            ),
        };
    }

    private function publishFeed(string $instagramId, string $accessToken, ?string $content, $media, ?string $aspectRatio): array
    {
        if ($media->count() > 1) {
            return $this->publishCarousel($instagramId, $accessToken, $content, $media, $aspectRatio);
        }

        $firstMedia = $media->first();

        if ($firstMedia->isVideo()) {
            return $this->publishReel($instagramId, $accessToken, $content, $firstMedia);
        }

        return $this->publishSingleImage($instagramId, $accessToken, $content, $firstMedia, $aspectRatio);
    }

    private function publishSingleImage(string $instagramId, string $accessToken, ?string $content, $media, ?string $aspectRatio): array
    {
        $imageUrl = $this->cropImageForAspectRatio($media->url, $aspectRatio);

        $params = [
            'image_url' => $imageUrl,
            'caption' => $content,
            'access_token' => $accessToken,
        ];

        $alt = $media->altTextFor(Platform::Instagram);

        if ($alt !== null) {
            $params['alt_text'] = $alt;
        }

        $containerId = $this->createContainer($instagramId, $params, 'container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishReel(string $instagramId, string $accessToken, ?string $content, $media): array
    {
        $containerId = $this->createContainer($instagramId, [
            'video_url' => $media->url,
            'caption' => $content,
            'media_type' => 'REELS',
            'access_token' => $accessToken,
        ], 'reel container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishStory(string $instagramId, string $accessToken, $media): array
    {
        $isVideo = $media->isVideo();

        $params = [
            'media_type' => 'STORIES',
            'access_token' => $accessToken,
        ];

        if ($isVideo) {
            $params['video_url'] = $media->url;
        } else {
            $dimensions = ContentType::InstagramStory->aiImageDimensions();
            $params['image_url'] = $this->fitImageToCanvas($media->url, data_get($dimensions, 'width'), data_get($dimensions, 'height'));
        }

        $containerId = $this->createContainer($instagramId, $params, 'story container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishCarousel(string $instagramId, string $accessToken, ?string $content, $mediaCollection, ?string $aspectRatio): array
    {
        // Step 1: Create containers for each media item
        $childContainers = [];
        $processingChildContainers = [];

        foreach ($mediaCollection as $media) {
            $isVideo = $media->isVideo();

            $params = [
                'is_carousel_item' => 'true',
                'access_token' => $accessToken,
            ];

            if ($isVideo) {
                $params['video_url'] = $media->url;
                $params['media_type'] = 'VIDEO';
            } else {
                $params['image_url'] = $this->cropImageForAspectRatio($media->url, $aspectRatio);

                $alt = $media->altTextFor(Platform::Instagram);

                if ($alt !== null) {
                    $params['alt_text'] = $alt;
                }
            }

            $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", $params);

            if ($containerResponse->failed()) {
                Log::error('Instagram carousel item creation failed', [
                    'body' => $this->redactResponseBody($containerResponse->body()),
                ]);

                continue;
            }

            $childId = $this->stringId(data_get($containerResponse->json(), 'id'));

            if ($childId === null) {
                Log::error('Instagram carousel item creation returned no ID', ['body' => $this->redactResponseBody($containerResponse->body())]);

                continue;
            }

            $childContainers[] = $childId;

            if ($isVideo) {
                $processingChildContainers[] = $childId;
            }
        }

        if (empty($childContainers)) {
            throw new InstagramPublishException(
                userMessage: 'Failed to create any carousel items',
                category: ErrorCategory::ServerError,
            );
        }

        return $this->finishCarousel($instagramId, $accessToken, $content, $childContainers, $processingChildContainers);
    }

    /**
     * @param  list<string>  $childContainers
     * @param  list<string>  $processingChildContainers
     */
    private function finishCarousel(string $instagramId, string $accessToken, ?string $content, array $childContainers, array $processingChildContainers): array
    {
        $workflow = [
            'stage' => self::WORKFLOW_CAROUSEL_CHILDREN,
            'child_container_ids' => $childContainers,
            'processing_child_container_ids' => $processingChildContainers,
        ];

        foreach ($processingChildContainers as $childId) {
            $this->waitForMediaProcessing($childId, $accessToken, $workflow);
        }

        $carouselId = $this->createContainer($instagramId, [
            'media_type' => 'CAROUSEL',
            'caption' => $content,
            'children' => implode(',', $childContainers),
            'access_token' => $accessToken,
        ], 'carousel container');

        return $this->finishContainer($instagramId, $accessToken, $carouselId);
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function resumeWorkflow(string $instagramId, string $accessToken, ?string $content, array $workflow): array
    {
        $mediaId = $this->stringId(data_get($workflow, 'media_id'));

        if ($mediaId !== null) {
            return $this->publishedMedia($mediaId, $accessToken);
        }

        $stage = data_get($workflow, 'stage');

        if ($stage === self::WORKFLOW_FINAL_CONTAINER) {
            $containerId = $this->stringId(data_get($workflow, 'container_id'));

            if ($containerId !== null) {
                return $this->finishContainer($instagramId, $accessToken, $containerId);
            }
        }

        if ($stage === self::WORKFLOW_CAROUSEL_CHILDREN) {
            $children = $this->stringList(data_get($workflow, 'child_container_ids'));
            $processingChildren = $this->stringList(data_get($workflow, 'processing_child_container_ids', []));

            if ($children !== null && $children !== [] && $processingChildren !== null) {
                return $this->finishCarousel(
                    $instagramId,
                    $accessToken,
                    $content,
                    $children,
                    $processingChildren,
                );
            }
        }

        throw new InstagramPublishException(
            userMessage: 'Instagram publish state is invalid and cannot be resumed.',
            category: ErrorCategory::ServerError,
        );
    }

    private function finishContainer(string $instagramId, string $accessToken, string $containerId): array
    {
        $status = $this->waitForMediaProcessing($containerId, $accessToken, [
            'stage' => self::WORKFLOW_FINAL_CONTAINER,
            'container_id' => $containerId,
        ]);

        if ($status === ContainerStatus::Published) {
            return $this->alreadyPublishedContainer($containerId);
        }

        return $this->publishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishContainer(string $instagramId, string $accessToken, string $containerId): array
    {
        $workflow = [
            'stage' => self::WORKFLOW_FINAL_CONTAINER,
            'container_id' => $containerId,
        ];

        $publishResponse = $this->sendGraphRequest(
            fn (): Response => $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media_publish", [
                'creation_id' => $containerId,
                'access_token' => $accessToken,
            ]),
            $containerId,
            $workflow,
        );

        if ($publishResponse->failed()) {
            Log::error('Instagram publish failed', [
                'status' => $publishResponse->status(),
                'body' => $this->redactResponseBody($publishResponse->body()),
            ]);

            if (GraphError::isTransientFailure($publishResponse)) {
                throw $this->pendingContainerException($containerId, $workflow, $publishResponse->status());
            }

            $this->handleApiError($publishResponse);
        }

        $mediaId = $this->requireGraphId(
            $publishResponse->json()['id'] ?? null,
            'Instagram publish failed: no media ID returned',
        );

        $this->rememberPublishedMedia($containerId, $mediaId);

        return $this->publishedMedia($mediaId, $accessToken);
    }

    protected function cropFailureException(string $message): SocialPublishException
    {
        return new InstagramPublishException(
            userMessage: $message,
            category: ErrorCategory::ServerError,
        );
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function waitForMediaProcessing(string $containerId, string $accessToken, array $workflow): ContainerStatus
    {
        $statusResponse = $this->sendGraphRequest(
            fn (): Response => $this->socialHttp()->get("{$this->baseUrl}/{$containerId}", [
                'fields' => 'status_code',
                'access_token' => $accessToken,
            ]),
            $containerId,
            $workflow,
        );

        if ($statusResponse->failed()) {
            if (! GraphError::isTransientFailure($statusResponse)) {
                $this->handleApiError($statusResponse);
            }

            throw $this->pendingContainerException($containerId, $workflow, $statusResponse->status());
        }

        $status = ContainerStatus::tryFrom((string) ($statusResponse->json()['status_code'] ?? ''));

        return match ($status) {
            ContainerStatus::Finished, ContainerStatus::Published => $status,
            ContainerStatus::Error => throw new InstagramPublishException(
                userMessage: 'Instagram media processing failed',
                category: ErrorCategory::ServerError,
            ),
            ContainerStatus::Expired => throw new InstagramPublishException(
                userMessage: 'Media container expired. Please try again in a few minutes.',
                category: ErrorCategory::ServerError,
            ),
            default => throw $this->pendingContainerException($containerId, $workflow),
        };
    }

    /**
     * Persist the media id before the permalink fetch so a crash after
     * media_publish can resume with the real id instead of guessing from /media.
     */
    private function rememberPublishedMedia(string $containerId, string $mediaId): void
    {
        $this->postPlatform->update([
            'error_context' => [
                ...($this->postPlatform->error_context ?? []),
                PublishCheckpoint::INSTAGRAM_WORKFLOW => [
                    'stage' => self::WORKFLOW_FINAL_CONTAINER,
                    'container_id' => $containerId,
                    'media_id' => $mediaId,
                ],
            ],
        ]);
    }

    /**
     * @return array{id: string, url: string|null}
     */
    private function publishedMedia(string $mediaId, string $accessToken): array
    {
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

        $permalink = data_get($permalinkResponse->json(), 'permalink');

        return [
            'id' => $mediaId,
            'url' => $this->stringId($permalink),
        ];
    }

    /**
     * The container node has no published media id. Listing /media or /stories
     * and taking data.0 can bind a different post from the same account, so
     * keep the container id until media_publish has checkpointed a media_id.
     *
     * @return array{id: string, url: string|null}
     */
    private function alreadyPublishedContainer(string $containerId): array
    {
        return [
            'id' => $containerId,
            'url' => null,
        ];
    }

    /**
     * @param  Closure(): Response  $request
     * @param  array<string, mixed>|null  $workflow
     */
    private function sendGraphRequest(Closure $request, ?string $containerId = null, ?array $workflow = null): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            if ($containerId !== null && $workflow !== null) {
                throw $this->pendingContainerException($containerId, $workflow);
            }

            throw new PlatformUnavailableException(
                message: "Instagram API unreachable: {$e->getMessage()}",
                retryDelaySeconds: self::STATUS_RETRY_DELAY_SECONDS,
                maxRetries: self::STATUS_MAX_RETRIES,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function pendingContainerException(string $containerId, array $workflow, ?int $httpStatus = null): PlatformUnavailableException
    {
        return new PlatformUnavailableException(
            message: "Instagram is still processing container {$containerId}",
            httpStatus: $httpStatus,
            context: [PublishCheckpoint::INSTAGRAM_WORKFLOW => $workflow],
            retryDelaySeconds: self::STATUS_RETRY_DELAY_SECONDS,
            maxRetries: self::STATUS_MAX_RETRIES,
        );
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function createContainer(string $instagramId, array $parameters, string $label): string
    {
        $response = $this->sendGraphRequest(
            fn (): Response => $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", $parameters),
        );

        if ($response->failed()) {
            Log::error("Instagram {$label} creation failed", [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);

            if (GraphError::isTransientFailure($response)) {
                throw new PlatformUnavailableException(
                    message: "Instagram {$label} creation failed transiently",
                    httpStatus: $response->status(),
                    retryDelaySeconds: self::STATUS_RETRY_DELAY_SECONDS,
                    maxRetries: self::STATUS_MAX_RETRIES,
                );
            }

            $this->handleApiError($response);
        }

        return $this->requireGraphId(
            data_get($response->json(), 'id'),
            "Instagram {$label} creation failed: No container ID returned",
        );
    }

    private function requireGraphId(mixed $id, string $missingMessage): string
    {
        $resolved = $this->stringId($id);

        if ($resolved === null) {
            throw new InstagramPublishException(
                userMessage: $missingMessage,
                category: ErrorCategory::ServerError,
            );
        }

        return $resolved;
    }

    private function stringId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $values): ?array
    {
        if (! is_array($values)) {
            return null;
        }

        return array_values(array_filter(
            $values,
            fn (mixed $value): bool => $this->stringId($value) !== null,
        ));
    }

    private function handleApiError(Response $response): never
    {
        throw InstagramPublishException::fromApiResponse($response);
    }
}

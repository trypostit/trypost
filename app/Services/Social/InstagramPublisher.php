<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
use App\Enums\Instagram\ContainerStatus;
use App\Enums\Instagram\TrialGraduation;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\Post;
use App\Services\Social\Concerns\FitsImageToCanvas;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\Meta\GraphError;
use App\Support\PostPlatformMetaRules;
use App\Support\Social\PublishCheckpoint;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class InstagramPublisher
{
    use FitsImageToCanvas;
    use HasSocialHttpClient;

    private string $baseUrl;

    private Post $post;

    private const int STATUS_RETRY_DELAY_SECONDS = 60;

    /** Two hours of delayed retries for processing and transient Graph failures. */
    private const int STATUS_MAX_RETRIES = 120;

    private const string WORKFLOW_CAROUSEL_CHILDREN = 'carousel_children';

    private const string WORKFLOW_FINAL_CONTAINER = 'final_container';

    /**
     * Facebook place ID from the post's `meta.location_id` — tags the post
     * with a location. Applied to feed/reel/carousel containers; the API
     * does not accept it on stories or carousel children.
     */
    private ?string $locationId = null;

    public function publish(Post $post): array
    {
        $this->post = $post;
        $this->validateContentLength($post);

        $account = $post->socialAccount;
        $this->baseUrl = $account->platform->instagramGraphBaseUrl();
        $this->locationId = trim((string) data_get($post->meta, 'location_id')) ?: null;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $instagramId = $account->platform_user_id;
        $accessToken = $account->access_token;

        $content = app(ContentSanitizer::class)->forPost($post);

        $pendingWorkflow = PublishCheckpoint::instagramWorkflow($post->error_context);

        if ($pendingWorkflow !== null) {
            return $this->resumeWorkflow($instagramId, $accessToken, $content, $pendingWorkflow);
        }

        $media = $post->mediaItems;

        if ($media->isEmpty()) {
            throw new InstagramPublishException(
                userMessage: 'Instagram requires at least one image or video.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $firstMedia = $media->first();
        $contentType = $post->content_type;

        return match ($contentType) {
            ContentType::InstagramReel => $this->publishReel($instagramId, $accessToken, $content, $firstMedia),
            ContentType::InstagramStory => $this->publishStory($instagramId, $accessToken, $firstMedia),
            ContentType::InstagramFeed => $this->publishFeed($instagramId, $accessToken, $content, $media),
            default => throw new InstagramPublishException(
                userMessage: "Unsupported Instagram content type: {$contentType?->value}",
                category: ErrorCategory::ContentPolicy,
            ),
        };
    }

    private function publishFeed(string $instagramId, string $accessToken, ?string $content, $media): array
    {
        if ($media->count() > 1) {
            return $this->publishCarousel($instagramId, $accessToken, $content, $media);
        }

        $firstMedia = $media->first();

        if ($firstMedia->isVideo()) {
            return $this->publishReel($instagramId, $accessToken, $content, $firstMedia);
        }

        return $this->publishSingleImage($instagramId, $accessToken, $content, $firstMedia);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function withLocation(array $params): array
    {
        if ($this->locationId !== null) {
            $params['location_id'] = $this->locationId;
        }

        return $params;
    }

    private function publishSingleImage(string $instagramId, string $accessToken, ?string $content, $media): array
    {
        $params = [
            'image_url' => $media->url,
            'caption' => $content,
            'access_token' => $accessToken,
        ];

        $alt = $media->altTextFor(Platform::Instagram);

        if ($alt !== null) {
            $params['alt_text'] = $alt;
        }

        $params = [...$params, ...$this->jsonListParam('user_tags', $media->userTags()), ...$this->sharedOptions(), ...$this->collaboratorsParam()];

        $containerId = $this->createContainer($instagramId, $this->withLocation($params), 'container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishReel(string $instagramId, string $accessToken, ?string $content, $media): array
    {
        $containerId = $this->createContainer($instagramId, $this->withLocation([
            'video_url' => $media->url,
            'caption' => $content,
            'media_type' => 'REELS',
            'access_token' => $accessToken,
            ...$this->thumbOffsetParam($media),
            ...$this->sharedOptions(),
            ...$this->collaboratorsParam(),
            ...$this->reelOptions(),
        ]), 'reel container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishStory(string $instagramId, string $accessToken, $media): array
    {
        $isVideo = $media->isVideo();

        $params = [
            'media_type' => 'STORIES',
            'access_token' => $accessToken,
            ...$this->sharedOptions(),
        ];

        if ($isVideo) {
            $params['video_url'] = $media->url;
        } else {
            $dimensions = ContentType::InstagramStory->preferredImageDimensions();
            $params['image_url'] = $this->fitImageToCanvas($media->url, data_get($dimensions, 'width'), data_get($dimensions, 'height'));
            $params = [...$params, ...$this->jsonListParam('user_tags', array_map(
                fn (array $tag): array => ['username' => $tag['username']],
                $media->userTags(),
            ))];
        }

        $containerId = $this->createContainer($instagramId, $params, 'story container');

        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishCarousel(string $instagramId, string $accessToken, ?string $content, $mediaCollection): array
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
                $params = [...$params, ...$this->thumbOffsetParam($media)];
            } else {
                $params['image_url'] = $media->url;

                $alt = $media->altTextFor(Platform::Instagram);

                if ($alt !== null) {
                    $params['alt_text'] = $alt;
                }

                $params = [...$params, ...$this->jsonListParam('user_tags', $media->userTags())];
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
     * Instagram reads list parameters (`user_tags`, `collaborators`) as JSON
     * arrays, and an empty one is left out.
     *
     * @param  list<mixed>  $values
     * @return array<string, string>
     */
    private function jsonListParam(string $name, array $values): array
    {
        return $values === [] ? [] : [$name => (string) json_encode($values)];
    }

    /**
     * Options Instagram accepts on every container except carousel children,
     * which never get the AI label.
     *
     * @return array<string, string>
     */
    private function sharedOptions(): array
    {
        return array_filter([
            'is_ai_generated' => data_get($this->post->meta, 'is_ai_generated') === true ? 'true' : null,
        ], fn (?string $value): bool => $value !== null);
    }

    /**
     * Reel-only options. A feed video is also sent as REELS, so these apply only
     * when the user picked the Reel content type.
     *
     * @return array<string, string>
     */
    private function reelOptions(): array
    {
        if ($this->post->content_type !== ContentType::InstagramReel) {
            return [];
        }

        return array_filter([
            'share_to_feed' => data_get($this->post->meta, 'share_to_feed', true) ? 'true' : 'false',
            'trial_params' => $this->trialParams(),
            'audio_configuration' => $this->audioConfiguration(),
        ]);
    }

    /**
     * A trial reel is shown to non-followers first, then graduated by hand
     * or by Instagram when it performs well.
     */
    private function trialParams(): ?string
    {
        return transform(
            TrialGraduation::tryFrom((string) data_get($this->post->meta, 'trial_graduation')),
            fn (TrialGraduation $graduation): string => (string) json_encode(['graduation_strategy' => $graduation->value]),
        );
    }

    /**
     * The picked music, which the Audio API only takes from an account
     * connected through Facebook. A volume left out is Instagram's default.
     */
    private function audioConfiguration(): ?string
    {
        $audioId = data_get($this->post->meta, 'audio.id');

        if (blank($audioId)) {
            return null;
        }

        if ($this->post->socialAccount?->platform !== Platform::InstagramFacebook) {
            throw new InstagramPublishException(
                userMessage: __('posts.form.instagram.music.requires_facebook'),
                category: ErrorCategory::ContentPolicy,
            );
        }

        $volume = fn (string $key): ?int => transform(data_get($this->post->meta, "audio.{$key}"), fn (mixed $value): int => (int) $value);

        return (string) json_encode(array_filter([
            'audio_id' => (string) $audioId,
            'audio_volume' => $volume('audio_volume'),
            'video_volume' => $volume('video_volume'),
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * Instagram reads `collaborators` as a JSON array of usernames. Stories and
     * carousel children take none.
     *
     * @return array{collaborators?: string}
     */
    private function collaboratorsParam(): array
    {
        return $this->jsonListParam('collaborators', PostPlatformMetaRules::instagramCollaborators(
            (array) data_get($this->post->meta, 'collaborators', []),
        ));
    }

    /**
     * @return array{thumb_offset?: int}
     */
    private function thumbOffsetParam(MediaItem $media): array
    {
        $offset = $media->coverOffsetMs();

        return $offset === null ? [] : ['thumb_offset' => $offset];
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

        $carouselId = $this->createContainer($instagramId, $this->withLocation([
            'media_type' => 'CAROUSEL',
            'caption' => $content,
            'children' => implode(',', $childContainers),
            'access_token' => $accessToken,
            ...$this->sharedOptions(),
            ...$this->collaboratorsParam(),
        ]), 'carousel container');

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

            if (! InstagramPublishException::isDocumentedRejection($publishResponse)
                && (GraphError::isTransientFailure($publishResponse) || InstagramPublishException::isMediaNotReady($publishResponse))) {
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
                'fields' => 'status_code,status',
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

        $statusCode = (string) ($statusResponse->json()['status_code'] ?? '');
        $status = ContainerStatus::tryFrom($statusCode);

        return match ($status) {
            ContainerStatus::Finished, ContainerStatus::Published => $status,
            ContainerStatus::Error => throw $this->mediaProcessingFailedException($statusResponse),
            ContainerStatus::Expired => throw new InstagramPublishException(
                userMessage: 'Media container expired. Please try again in a few minutes.',
                category: ErrorCategory::ServerError,
                rawResponse: $this->redactResponseBody($statusResponse->body()),
            ),
            default => throw $this->pendingContainerException(
                $containerId,
                $workflow,
                statusCode: $statusCode !== '' ? $statusCode : null,
            ),
        };
    }

    /**
     * Persist the media id before the permalink fetch so a crash after
     * media_publish can resume with the real id instead of guessing from /media.
     */
    private function rememberPublishedMedia(string $containerId, string $mediaId): void
    {
        $this->post->writePublication([
            'error_context' => [
                ...($this->post->error_context ?? []),
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
    private function pendingContainerException(string $containerId, array $workflow, ?int $httpStatus = null, ?string $statusCode = null): PlatformUnavailableException
    {
        $context = [PublishCheckpoint::INSTAGRAM_WORKFLOW => $workflow];

        if (is_string($statusCode) && $statusCode !== '') {
            $context[PublishCheckpoint::INSTAGRAM_STATUS] = $statusCode;
        }

        return new PlatformUnavailableException(
            message: "Instagram is still processing container {$containerId}",
            httpStatus: $httpStatus,
            context: $context,
            retryDelaySeconds: self::STATUS_RETRY_DELAY_SECONDS,
            maxRetries: self::STATUS_MAX_RETRIES,
        );
    }

    private function mediaProcessingFailedException(Response $response): InstagramPublishException
    {
        $body = $this->redactResponseBody($response->body());

        Log::error('Instagram media processing failed', [
            'status_code' => data_get($response->json(), 'status_code'),
            'status' => data_get($response->json(), 'status'),
            'body' => $body,
        ]);

        return InstagramPublishException::fromContainerStatus(data_get($response->json(), 'status'), $body);
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

            if (! InstagramPublishException::isDocumentedRejection($response) && GraphError::isTransientFailure($response)) {
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

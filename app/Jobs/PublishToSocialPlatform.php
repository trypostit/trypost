<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Post\FinalizePostPublication;
use App\Enums\GoogleBusiness\LocalPostState;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Events\PostPlatformStatusUpdated;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\PostPlatform;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\Discord\DiscordPublisher;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\GoogleBusinessPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use App\Services\Social\MastodonPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\Telegram\TelegramPublisher;
use App\Services\Social\ThreadsPublisher;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\VkPublisher;
use App\Services\Social\XPublisher;
use App\Services\Social\YouTubePublisher;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use App\Support\Social\LimitRetryPolicy;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\ThreadProgress;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use App\Support\ThreadReplies;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishToSocialPlatform implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $maxExceptions = 1;

    /** Download/upload + Pinterest poll headroom; keep Horizon/Redis timeouts above this. */
    public int $timeout = 900;

    public int $uniqueFor = 960;

    /** Default platform-unavailable retry budget (~1 hour at 10 minutes each). */
    public const MAX_PLATFORM_UNAVAILABLE_RETRIES = 6;

    private const int DEFAULT_RETRY_DELAY_SECONDS = 600;

    public function __construct(
        public PostPlatform $postPlatform,
        public int $uniqueAttempt = 0,
    ) {
        $this->onQueue($postPlatform->platform->queue());
    }

    public function uniqueId(): string
    {
        return "{$this->postPlatform->id}:{$this->uniqueAttempt}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("social-publish:{$this->postPlatform->id}"))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(): void
    {
        $this->postPlatform->refresh();

        if ($this->postPlatform->status->isClosed() || $this->postPlatform->isWaitingForLimitRetry()) {
            return;
        }

        if ($this->postPlatform->socialAccount->status === Status::Disconnected) {
            $this->failAndFinalize(__('posts.errors.account_disconnected'));

            return;
        }

        if ($this->postPlatform->socialAccount->status === Status::TokenExpired) {
            $this->failAndFinalize(__('posts.errors.account_token_expired'), [
                'category' => ErrorCategory::TokenExpired->value,
                'failed_at' => now()->toIso8601String(),
            ]);

            return;
        }

        if ($this->failForMissingScopes()) {
            return;
        }

        if ($this->failForInvalidMedia()) {
            return;
        }

        $this->postPlatform->markAsPublishing();
        $this->broadcastStatus();

        $maxAttempts = 2; // Original attempt + 1 retry after token refresh

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $publisher = $this->getPublisher();
                $result = $publisher->publish($this->postPlatform);

                $this->recordPublishResult($result);
                break;
            } catch (PlatformUnavailableException $e) {
                $this->rescheduleForRetry($e);
                break;
            } catch (TokenExpiredException $e) {
                if ($attempt < $maxAttempts) {
                    try {
                        $this->refreshAccountToken();

                        continue;
                    } catch (PlatformUnavailableException $refreshError) {
                        $this->rescheduleForRetry($refreshError);
                        break;
                    } catch (Throwable $refreshError) {
                        $this->reportCaughtPublishFailure($refreshError, [
                            'phase' => 'token_refresh',
                        ]);
                        $this->failWithExpiredToken($e);
                        break;
                    }
                }

                $this->reportCaughtPublishFailure($e);
                $this->failWithExpiredToken($e);
                break;
            } catch (SocialPublishException $e) {
                if ($e->isLimit() && $this->waitForLimitRetry($e)) {
                    break;
                }

                $this->reportCaughtPublishFailure($e);
                $this->markPlatformAsFailed($e->userMessage, $this->failureContext([
                    'category' => $e->category->value,
                    'platform_error_code' => $e->platformErrorCode,
                    'raw_response' => $e->context()['raw_response'],
                ]));
                break;
            } catch (Throwable $e) {
                $this->reportCaughtPublishFailure($e);
                $this->markPlatformAsFailed($this->safeFailureMessage($e), $this->failureContext([
                    'category' => ErrorCategory::Unknown->value,
                ]));
                break;
            }
        }

        $this->updatePostStatus();
        $this->broadcastStatus();
    }

    /**
     * A publisher may answer with a provider-side state instead of a finished
     * post. Anything it does not report is a plain success, which is every
     * platform but Google Business Profile.
     *
     * @param  array<string, mixed>  $result
     */
    private function recordPublishResult(array $result): void
    {
        $platformPostId = (string) data_get($result, 'id');
        $platformUrl = data_get($result, 'url');

        // tryFrom, not fromApi: every other publisher omits `state`. fromApi(null)
        // is Processing, which would hold LinkedIn/X/… in pending review forever.
        $state = LocalPostState::tryFrom((string) data_get($result, 'state'));
        $threadReplyIds = data_get($result, 'thread_reply_ids');

        if (is_array($threadReplyIds) && $threadReplyIds !== []) {
            $this->postPlatform->thread_reply_ids = array_values($threadReplyIds);
        }

        match ($state) {
            LocalPostState::Rejected => $this->postPlatform->markAsRejected(
                $platformPostId,
                $platformUrl,
                __('posts.errors.rejected_in_review'),
                ['provider_state' => $state->value],
            ),
            LocalPostState::Processing,
            LocalPostState::Scheduled,
            LocalPostState::Unspecified => $this->postPlatform->markAsPendingReview($platformPostId, $platformUrl),
            LocalPostState::Live,
            LocalPostState::Recurring,
            null => $this->postPlatform->markAsPublished($platformPostId, $platformUrl),
        };
    }

    private function refreshAccountToken(): void
    {
        app(ConnectionVerifier::class)->verify($this->postPlatform->socialAccount);
    }

    private function failForMissingScopes(): bool
    {
        $missingScopes = array_values(array_diff(
            $this->postPlatform->platform->requiredPublishScopes(),
            $this->postPlatform->socialAccount->scopes ?? [],
        ));

        if ($missingScopes === []) {
            return false;
        }

        $this->failAndFinalize(
            'Missing permissions: '.implode(', ', $missingScopes).'. Please reconnect your account.',
            [
                'category' => ErrorCategory::Permission->value,
                'missing_scopes' => $missingScopes,
                'failed_at' => now()->toIso8601String(),
            ],
        );

        return true;
    }

    /**
     * Media can become invalid after scheduling (bounds changed in a release,
     * an edit through another path): recheck it against the same rule every
     * save runs, and fail without calling the network. A resume of a publish
     * already accepted by the provider (Instagram container, TikTok publish id)
     * is not rechecked: its media is already on the network. Neither is a
     * target scheduled before TryPost 2.0 (`scheduled_before_media_checks`),
     * which publishes its media as the pre-2.0 publishers did.
     */
    private function failForInvalidMedia(): bool
    {
        $context = $this->postPlatform->error_context;

        if ($this->postPlatform->scheduled_before_media_checks) {
            return false;
        }

        if (PublishCheckpoint::instagramWorkflow($context) !== null || PublishCheckpoint::tiktokPublishId($context) !== null) {
            return false;
        }

        $post = $this->postPlatform->post;
        $errors = ContentTypeCompatibleWithMedia::errorsFor(
            [[
                'key' => 'media',
                'content_type' => $this->postPlatform->content_type?->value,
            ]],
            (array) ($post->media ?? []),
            $post->workspace,
        ) ?: ThreadReplies::mediaErrors($this->postPlatform->platform, $this->postPlatform->meta, $post->workspace);

        if ($errors === []) {
            return false;
        }

        $this->failAndFinalize((string) array_first($errors), $this->failureContext([
            'reason' => 'media_invalid',
            'category' => ErrorCategory::MediaFormat->value,
        ]));

        return true;
    }

    private function rescheduleForRetry(PlatformUnavailableException $e): void
    {
        $previousContext = self::withSeparateRetryCounters($this->postPlatform->error_context ?? []);
        $hasOwnPolicy = $e->maxRetries !== null || $e->retryDelaySeconds !== null;
        $counterKey = $hasOwnPolicy ? 'processing_retry_count' : 'retry_count';
        $retryCount = (int) data_get($previousContext, $counterKey, 0) + 1;
        $maxRetries = $e->maxRetries ?? self::MAX_PLATFORM_UNAVAILABLE_RETRIES;
        $retryDelaySeconds = $e->retryDelaySeconds ?? self::DEFAULT_RETRY_DELAY_SECONDS;
        $context = [
            ...Arr::except($previousContext, ['max_retries', 'retry_delay_seconds']),
            ...$e->context,
            'category' => ErrorCategory::PlatformUnavailable->value,
            'http_status' => $e->httpStatus,
            $counterKey => $retryCount,
            ...($hasOwnPolicy ? ['max_retries' => $maxRetries, 'retry_delay_seconds' => $retryDelaySeconds] : []),
            'detail' => $e->getMessage(),
        ];

        if ($retryCount > $maxRetries) {
            $this->reportCaughtPublishFailure($e, [
                'retry_count' => $retryCount,
                'max_retries' => $maxRetries,
            ]);

            $this->markPlatformAsFailed(
                __('posts.errors.platform_unavailable_exhausted'),
                [...$context, 'failed_at' => now()->toIso8601String()],
            );

            return;
        }

        $nextAttemptAt = now()->addSeconds($retryDelaySeconds);

        Log::warning('Publish rescheduled: platform unavailable', [
            'post_platform_id' => $this->postPlatform->id,
            'platform' => $this->postPlatform->platform->value,
            'next_attempt_at' => $nextAttemptAt->toIso8601String(),
            ...$context,
        ]);

        $this->postPlatform->update([
            'status' => PostPlatformStatus::Retrying,
            'error_message' => __('posts.errors.platform_unavailable'),
            'error_context' => [
                ...$context,
                'last_attempt_at' => now()->toIso8601String(),
                'next_attempt_at' => $nextAttemptAt->toIso8601String(),
            ],
        ]);

        self::dispatch(
            $this->postPlatform,
            (int) data_get($context, 'retry_count', 0) + (int) data_get($context, 'processing_retry_count', 0),
        )->delay($nextAttemptAt);
    }

    /**
     * Before TryPost 2.0 a status poll counted on `retry_count` and stored
     * `max_retries`, with no `processing_retry_count`. That count belongs to
     * the poll budget, so the outage count of such a row starts over.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function withSeparateRetryCounters(array $context): array
    {
        if (! array_key_exists('max_retries', $context) || array_key_exists('processing_retry_count', $context)) {
            return $context;
        }

        return [
            ...$context,
            'processing_retry_count' => (int) data_get($context, 'retry_count', 0),
            'retry_count' => 0,
        ];
    }

    /**
     * A limit refusal (rate limit, posting quota, daily cap) is not a failure
     * yet: the target waits in Retrying until `retry_at`, when
     * ProcessScheduledPosts dispatches it again. A refused TikTok publish_id
     * is dead and X media ids expire before a long wait ends, so both are
     * dropped; thread checkpoints stay so the retry resumes.
     * Returns false once the retries are spent.
     */
    private function waitForLimitRetry(SocialPublishException $e): bool
    {
        $previousContext = $this->postPlatform->error_context ?? [];
        $retries = LimitRetryPolicy::retriesSoFar($previousContext);
        $retryAt = LimitRetryPolicy::nextAttemptAt($retries, $e->retryAt);

        if ($retryAt === null) {
            return false;
        }

        match ($this->postPlatform->platform) {
            SocialPlatform::TikTok => app(TikTokPhotoDerivativeCleaner::class)->cleanup($previousContext, $this->postPlatform->id),
            SocialPlatform::GoogleBusiness => app(GoogleBusinessDerivativeCleaner::class)->cleanup($this->postPlatform->id),
            default => null,
        };

        $context = [
            ...Arr::except($previousContext, [
                PublishCheckpoint::TIKTOK_PUBLISH_ID,
                PublishCheckpoint::TIKTOK_STATUS,
                PublishCheckpoint::TIKTOK_DERIVATIVE_PATHS,
                PublishCheckpoint::X_MEDIA,
            ]),
            'category' => ErrorCategory::RateLimit->value,
            'platform_error_code' => $e->platformErrorCode,
            'raw_response' => data_get($e->context(), 'raw_response'),
            LimitRetryPolicy::ATTEMPTS_KEY => $retries + 1,
            'last_attempt_at' => now()->toIso8601String(),
        ];

        Log::warning('Publish waiting for a network limit', [
            'post_platform_id' => $this->postPlatform->id,
            'platform' => $this->postPlatform->platform->value,
            'platform_error_code' => $e->platformErrorCode,
            'limit_retries' => $retries + 1,
            'retry_at' => $retryAt->toIso8601String(),
        ]);

        $this->postPlatform->markAsWaitingForLimitRetry($retryAt, $e->userMessage, $context);

        return true;
    }

    /**
     * Every caught publish failure is logged with the post/platform ids that
     * Nightwatch's exception record lacks. Only an expired token and a
     * documented network rejection the user must act on are not reported;
     * everything else can be ours, so it reaches Nightwatch.
     *
     * @param  array<string, mixed>  $context
     */
    private function reportCaughtPublishFailure(Throwable $e, array $context = []): void
    {
        Log::error('Social publish failed', [
            ...(method_exists($e, 'context') ? $e->context() : []),
            'post_platform_id' => $this->postPlatform->id,
            'platform' => $this->postPlatform->platform->value,
            'content_type' => $this->postPlatform->content_type?->value,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            ...$context,
            'media' => $this->mediaSnapshot($this->postPlatform),
        ]);

        if ($e instanceof TokenExpiredException || ($e instanceof SocialPublishException && $e->isNetworkRejection())) {
            return;
        }

        report($e);
    }

    /**
     * Nightwatch's exception record is class/message/stack only. The
     * structured log needs the media the platform tried to pull so a
     * CDN miss can be told from an API rejection.
     *
     * @return list<array{url: ?string, mime_type: ?string, size: ?int, type: ?string}>
     */
    private function mediaSnapshot(PostPlatform $postPlatform): array
    {
        $media = $postPlatform->post?->media;

        if (! is_array($media)) {
            return [];
        }

        return array_values(array_map(function (mixed $item): array {
            $item = is_array($item) ? $item : [];
            $url = data_get($item, 'url');
            $mimeType = data_get($item, 'mime_type');
            $path = data_get($item, 'original_filename') ?? data_get($item, 'path');
            $type = MediaType::classify(
                is_string($mimeType) ? $mimeType : null,
                is_string($path) ? $path : null,
            );

            return [
                'url' => is_string($url) ? $url : null,
                'mime_type' => is_string($mimeType) ? $mimeType : null,
                'size' => is_numeric(data_get($item, 'size')) ? (int) data_get($item, 'size') : null,
                'type' => $type?->value,
            ];
        }, $media));
    }

    private function failWithExpiredToken(TokenExpiredException $e): void
    {
        $this->markPlatformAsFailed($e->getMessage(), [
            'category' => ErrorCategory::TokenExpired->value,
            'platform_error_code' => $e->platformErrorCode,
            'failed_at' => now()->toIso8601String(),
        ]);
        $this->postPlatform->socialAccount->markAsTokenExpired($e->getMessage());
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function markPlatformAsFailed(string $message, ?array $context = null): void
    {
        $previousContext = $this->postPlatform->error_context ?? [];

        match ($this->postPlatform->platform) {
            SocialPlatform::TikTok => app(TikTokPhotoDerivativeCleaner::class)->cleanupUnlessPublishInFlight(
                $previousContext,
                $this->postPlatform->id,
            ),
            SocialPlatform::GoogleBusiness => app(GoogleBusinessDerivativeCleaner::class)->cleanup($this->postPlatform->id),
            default => null,
        };

        $failureContext = [...$previousContext, ...($context ?? [])];

        $this->postPlatform->markAsFailed(
            ThreadProgress::failureMessage($this->postPlatform, $message, $failureContext),
            $failureContext === [] ? null : $failureContext,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function failureContext(array $extra = []): array
    {
        return [
            ...$extra,
            'failed_at' => now()->toIso8601String(),
            'content_length' => mb_strlen($this->postPlatform->post->content ?? ''),
            'media_count' => count($this->postPlatform->post->media ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function failAndFinalize(string $message, ?array $context = null): void
    {
        $this->markPlatformAsFailed($message, $context);
        $this->updatePostStatus();
        $this->broadcastStatus();
    }

    private function broadcastStatus(): void
    {
        PostPlatformStatusUpdated::dispatch($this->postPlatform->fresh());
    }

    /**
     * A user-safe failure message: only our own publish exceptions are shown
     * verbatim; anything else is genericized so internals never reach the email.
     */
    private function safeFailureMessage(Throwable $e): string
    {
        return $e instanceof SocialPublishException
            ? $e->userMessage
            : 'An unexpected error occurred while publishing. Please try again.';
    }

    private function getPublisher(): LinkedInPublisher|LinkedInPagePublisher|XPublisher|TikTokPublisher|YouTubePublisher|FacebookPublisher|InstagramPublisher|ThreadsPublisher|PinterestPublisher|BlueskyPublisher|MastodonPublisher|TelegramPublisher|DiscordPublisher|GoogleBusinessPublisher|VkPublisher
    {
        return match ($this->postPlatform->platform) {
            SocialPlatform::LinkedIn => app(LinkedInPublisher::class),
            SocialPlatform::LinkedInPage => app(LinkedInPagePublisher::class),
            SocialPlatform::X => app(XPublisher::class),
            SocialPlatform::TikTok => app(TikTokPublisher::class),
            SocialPlatform::YouTube => app(YouTubePublisher::class),
            SocialPlatform::Facebook => app(FacebookPublisher::class),
            SocialPlatform::Instagram, SocialPlatform::InstagramFacebook => app(InstagramPublisher::class),
            SocialPlatform::Threads => app(ThreadsPublisher::class),
            SocialPlatform::Pinterest => app(PinterestPublisher::class),
            SocialPlatform::Bluesky => app(BlueskyPublisher::class),
            SocialPlatform::Mastodon => app(MastodonPublisher::class),
            SocialPlatform::Telegram => app(TelegramPublisher::class),
            SocialPlatform::Discord => app(DiscordPublisher::class),
            SocialPlatform::GoogleBusiness => app(GoogleBusinessPublisher::class),
            SocialPlatform::Vk => app(VkPublisher::class),
        };
    }

    private function updatePostStatus(): void
    {
        app(FinalizePostPublication::class)->handle($this->postPlatform->post);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('PublishToSocialPlatform job failed permanently', [
            'post_platform_id' => $this->postPlatform->id,
            'platform' => $this->postPlatform->platform->value,
            'error' => $exception?->getMessage(),
        ]);

        $this->postPlatform->refresh();

        if ($this->postPlatform->status->isClosed() || $this->postPlatform->isWaitingForLimitRetry()) {
            return;
        }

        $this->failAndFinalize(
            $exception ? $this->safeFailureMessage($exception) : 'Unknown error',
            [
                'category' => ErrorCategory::JobFailed->value,
                'failed_at' => now()->toIso8601String(),
            ],
        );
    }
}

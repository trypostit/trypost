<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Events\PostPlatformStatusUpdated;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\Discord\DiscordPublisher;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use App\Services\Social\MastodonPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\Telegram\TelegramPublisher;
use App\Services\Social\ThreadsPublisher;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\XPublisher;
use App\Services\Social\YouTubePublisher;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

        if ($this->isTerminal()) {
            return;
        }

        if (! $this->postPlatform->socialAccount->is_active) {
            $this->failAndFinalize(__('posts.errors.account_inactive'));

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

        $this->postPlatform->markAsPublishing();
        $this->broadcastStatus();

        $maxAttempts = 2; // Original attempt + 1 retry after token refresh

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $publisher = $this->getPublisher();
                $result = $publisher->publish($this->postPlatform);
                $this->postPlatform->markAsPublished(data_get($result, 'id'), data_get($result, 'url'));
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
                        Log::error('Token refresh failed during publish retry', [
                            'post_platform_id' => $this->postPlatform->id,
                            'platform' => $this->postPlatform->platform->value,
                            'error' => $refreshError->getMessage(),
                        ]);
                    }
                }

                // All attempts exhausted or refresh failed
                Log::error('Token expired while publishing to social platform', [
                    'post_platform_id' => $this->postPlatform->id,
                    'platform' => $this->postPlatform->platform->value,
                    'error' => $e->getMessage(),
                    'platform_error_code' => $e->platformErrorCode,
                ]);

                $this->markPlatformAsFailed($e->getMessage(), [
                    'category' => ErrorCategory::TokenExpired->value,
                    'platform_error_code' => $e->platformErrorCode,
                    'failed_at' => now()->toIso8601String(),
                ]);
                $this->postPlatform->socialAccount->markAsTokenExpired($e->getMessage());
                break;
            } catch (SocialPublishException $e) {
                Log::error('Social publish failed: '.$e->userMessage);
                $this->markPlatformAsFailed($e->userMessage, [
                    'category' => $e->category->value,
                    'platform_error_code' => $e->platformErrorCode,
                    'failed_at' => now()->toIso8601String(),
                    'content_length' => mb_strlen($this->postPlatform->post->content ?? ''),
                    'media_count' => count($this->postPlatform->post->media ?? []),
                    'raw_response' => $e->context()['raw_response'],
                ]);
                break;
            } catch (Throwable $e) {
                Log::error('Failed to publish to social platform', [
                    'post_platform_id' => $this->postPlatform->id,
                    'platform' => $this->postPlatform->platform->value,
                    'error' => $e->getMessage(),
                ]);
                $this->markPlatformAsFailed($this->safeFailureMessage($e), [
                    'category' => ErrorCategory::Unknown->value,
                    'failed_at' => now()->toIso8601String(),
                    'content_length' => mb_strlen($this->postPlatform->post->content ?? ''),
                    'media_count' => count($this->postPlatform->post->media ?? []),
                ]);
                break;
            }
        }

        // Always check and update post status after each platform finishes
        $this->updatePostStatus();

        // Broadcast final status
        $this->broadcastStatus();
    }

    private function refreshAccountToken(): void
    {
        $account = $this->postPlatform->socialAccount;

        // Delegate to ConnectionVerifier which already has per-platform refresh logic
        app(ConnectionVerifier::class)->verify($account);
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

    private function rescheduleForRetry(PlatformUnavailableException $e): void
    {
        $retryCount = (int) data_get($this->postPlatform->error_context, 'retry_count', 0) + 1;
        $maxRetries = (int) ($e->maxRetries
            ?? data_get($this->postPlatform->error_context, 'max_retries')
            ?? self::MAX_PLATFORM_UNAVAILABLE_RETRIES);
        $retryDelaySeconds = (int) ($e->retryDelaySeconds
            ?? data_get($this->postPlatform->error_context, 'retry_delay_seconds')
            ?? self::DEFAULT_RETRY_DELAY_SECONDS);
        $context = [
            ...($this->postPlatform->error_context ?? []),
            ...$e->context,
            'category' => ErrorCategory::PlatformUnavailable->value,
            'http_status' => $e->httpStatus,
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'retry_delay_seconds' => $retryDelaySeconds,
            'detail' => $e->getMessage(),
        ];

        if ($retryCount > $maxRetries) {
            Log::warning('Publish retries exhausted: platform unavailable', [
                'post_platform_id' => $this->postPlatform->id,
                'platform' => $this->postPlatform->platform->value,
                ...$context,
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

        self::dispatch($this->postPlatform, $retryCount)->delay($nextAttemptAt);
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    private function markPlatformAsFailed(string $message, ?array $context = null): void
    {
        $previousContext = $this->postPlatform->error_context ?? [];

        if ($this->postPlatform->platform === SocialPlatform::TikTok) {
            app(TikTokPhotoDerivativeCleaner::class)->cleanupUnlessPublishInFlight(
                $previousContext,
                $this->postPlatform->id,
            );
        }

        $failureContext = [...$previousContext, ...($context ?? [])];

        $this->postPlatform->markAsFailed($message, $failureContext === [] ? null : $failureContext);
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

    private function isTerminal(): bool
    {
        return in_array($this->postPlatform->status, [
            PostPlatformStatus::Published,
            PostPlatformStatus::Failed,
        ], true);
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

    private function getPublisher(): LinkedInPublisher|LinkedInPagePublisher|XPublisher|TikTokPublisher|YouTubePublisher|FacebookPublisher|InstagramPublisher|ThreadsPublisher|PinterestPublisher|BlueskyPublisher|MastodonPublisher|TelegramPublisher|DiscordPublisher
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
        };
    }

    private function updatePostStatus(): void
    {
        $post = $this->postPlatform->post->fresh();
        $enabledPlatforms = $post->postPlatforms->where('enabled', true);

        $total = $enabledPlatforms->count();
        $publishedCount = $enabledPlatforms->where('status', PostPlatformStatus::Published)->count();
        $failedCount = $enabledPlatforms->where('status', PostPlatformStatus::Failed)->count();
        $finishedCount = $publishedCount + $failedCount;

        // Only update post status when all platforms have finished
        if ($finishedCount < $total) {
            return;
        }

        if ($publishedCount === $total) {
            $post->markAsPublished();
            $this->notify($post, PostPlatformStatus::Published);

            return;
        }

        if ($publishedCount > 0) {
            $post->markAsPartiallyPublished();
        } else {
            $post->markAsFailed();
        }

        $this->notify($post, PostPlatformStatus::Failed);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('PublishToSocialPlatform job failed permanently', [
            'post_platform_id' => $this->postPlatform->id,
            'platform' => $this->postPlatform->platform->value,
            'error' => $exception?->getMessage(),
        ]);

        $this->postPlatform->refresh();

        if ($this->isTerminal()) {
            return;
        }

        $this->markPlatformAsFailed(
            $exception ? $this->safeFailureMessage($exception) : 'Unknown error',
            [
                'category' => ErrorCategory::JobFailed->value,
                'failed_at' => now()->toIso8601String(),
            ]
        );
        $this->updatePostStatus();
        $this->broadcastStatus();
    }

    private function notify(Post $post, PostPlatformStatus $status): void
    {
        $owner = $post->workspace->owner;

        if (! $owner) {
            return;
        }

        $successful = $status === PostPlatformStatus::Published;
        $platforms = $post->postPlatforms()
            ->with('socialAccount')
            ->enabled()
            ->where('status', $status)
            ->get()
            ->map(fn ($pp) => $pp->notificationLabel())
            ->implode(', ');

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $post->workspace_id,
            type: $successful ? Type::PostPublished : Type::PostFailed,
            channel: Channel::Both,
            title: $successful ? 'Post published successfully' : 'Post failed to publish',
            body: $successful ? $platforms : "Failed on: {$platforms}",
            data: ['post_id' => $post->id],
            mailable: $successful ? new PostPublished($post) : new PostPublishFailed($post),
        );
    }
}

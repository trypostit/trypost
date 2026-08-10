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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PublishToSocialPlatform implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Download/upload + Pinterest poll headroom; keep Horizon/Redis timeouts above this. */
    public int $timeout = 900;

    public int $uniqueFor = 960;

    /** Max platform-unavailable reschedules (~1 hour at 10 min each). */
    public const MAX_PLATFORM_UNAVAILABLE_RETRIES = 6;

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

    public function handle(): void
    {
        $this->postPlatform->refresh();

        if ($this->isTerminal()) {
            return;
        }

        if (! $this->postPlatform->socialAccount->is_active) {
            $this->postPlatform->markAsFailed(__('posts.errors.account_inactive'));
            $this->updatePostStatus();
            $this->broadcastStatus();

            return;
        }

        if ($this->postPlatform->socialAccount->status === Status::Disconnected) {
            $this->postPlatform->markAsFailed(__('posts.errors.account_disconnected'));
            $this->updatePostStatus();
            $this->broadcastStatus();

            return;
        }

        if ($this->postPlatform->socialAccount->status === Status::TokenExpired) {
            $this->postPlatform->markAsFailed(__('posts.errors.account_token_expired'), [
                'category' => 'token_expired',
                'failed_at' => now()->toIso8601String(),
            ]);
            $this->updatePostStatus();
            $this->broadcastStatus();

            return;
        }

        $requiredScopes = $this->postPlatform->platform->requiredPublishScopes();
        $accountScopes = $this->postPlatform->socialAccount->scopes ?? [];

        if (! empty($requiredScopes)) {
            $missingScopes = array_diff($requiredScopes, $accountScopes);

            if (! empty($missingScopes)) {
                $this->postPlatform->markAsFailed(
                    'Missing permissions: '.implode(', ', $missingScopes).'. Please reconnect your account.',
                    ['category' => 'permission', 'missing_scopes' => $missingScopes, 'failed_at' => now()->toIso8601String()]
                );
                $this->updatePostStatus();
                $this->broadcastStatus();

                return;
            }
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
                    } catch (\Throwable $refreshError) {
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

                $this->postPlatform->markAsFailed($e->getMessage(), [
                    'category' => 'token_expired',
                    'platform_error_code' => $e->platformErrorCode,
                    'failed_at' => now()->toIso8601String(),
                ]);
                $this->postPlatform->socialAccount->markAsTokenExpired($e->getMessage());
                break;
            } catch (SocialPublishException $e) {
                Log::error('Social publish failed: '.$e->userMessage);
                $this->postPlatform->markAsFailed($e->userMessage, [
                    'category' => $e->category->value,
                    'platform_error_code' => $e->platformErrorCode,
                    'failed_at' => now()->toIso8601String(),
                    'content_length' => mb_strlen($this->postPlatform->post->content ?? ''),
                    'media_count' => count($this->postPlatform->post->media ?? []),
                    'raw_response' => $e->context()['raw_response'],
                ]);
                break;
            } catch (\Throwable $e) {
                Log::error('Failed to publish to social platform', [
                    'post_platform_id' => $this->postPlatform->id,
                    'platform' => $this->postPlatform->platform->value,
                    'error' => $e->getMessage(),
                ]);
                $this->postPlatform->markAsFailed($this->safeFailureMessage($e), [
                    'category' => 'unknown',
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

    private function rescheduleForRetry(PlatformUnavailableException $e): void
    {
        $retryCount = (int) data_get($this->postPlatform->error_context, 'retry_count', 0) + 1;
        $context = [
            'category' => 'platform_unavailable',
            'http_status' => $e->httpStatus,
            'retry_count' => $retryCount,
            'detail' => $e->getMessage(),
        ];

        if ($retryCount > self::MAX_PLATFORM_UNAVAILABLE_RETRIES) {
            Log::warning('Publish retries exhausted: platform unavailable', [
                'post_platform_id' => $this->postPlatform->id,
                'platform' => $this->postPlatform->platform->value,
                ...$context,
            ]);

            $this->postPlatform->markAsFailed(
                __('posts.errors.platform_unavailable_exhausted'),
                [...$context, 'failed_at' => now()->toIso8601String()],
            );

            return;
        }

        $nextAttemptAt = now()->addMinutes(10);

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
    private function safeFailureMessage(\Throwable $e): string
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
            $this->notifySuccess($post);
        } elseif ($publishedCount > 0) {
            $post->markAsPartiallyPublished();
            $this->notifyFailure($post);
        } else {
            $post->markAsFailed();
            $this->notifyFailure($post);
        }
    }

    private function notifySuccess(Post $post): void
    {
        $owner = $post->workspace->owner;

        if (! $owner) {
            return;
        }

        $publishedPlatforms = $post->postPlatforms()
            ->with('socialAccount')
            ->enabled()
            ->get()
            ->filter(fn ($pp) => $pp->status === PostPlatformStatus::Published)
            ->map(fn ($pp) => $pp->platform->label().' (@'.data_get($pp, 'socialAccount.username', '').')')
            ->implode(', ');

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $post->workspace_id,
            type: Type::PostPublished,
            channel: Channel::Both,
            title: 'Post published successfully',
            body: $publishedPlatforms,
            data: ['post_id' => $post->id],
            mailable: new PostPublished($post),
        );
    }

    public function failed(?\Throwable $exception): void
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

        $this->postPlatform->markAsFailed(
            $exception ? $this->safeFailureMessage($exception) : 'Unknown error',
            [
                'category' => 'job_failed',
                'failed_at' => now()->toIso8601String(),
            ]
        );
        $this->updatePostStatus();
        $this->broadcastStatus();
    }

    private function notifyFailure(Post $post): void
    {
        $owner = $post->workspace->owner;

        if (! $owner) {
            return;
        }

        $failedPlatforms = $post->postPlatforms()
            ->with('socialAccount')
            ->enabled()
            ->get()
            ->filter(fn ($pp) => $pp->status === PostPlatformStatus::Failed)
            ->map(fn ($pp) => $pp->platform->label().' (@'.data_get($pp, 'socialAccount.username', '').')')
            ->implode(', ');

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $post->workspace_id,
            type: Type::PostFailed,
            channel: Channel::Both,
            title: 'Post failed to publish',
            body: "Failed on: {$failedPlatforms}",
            data: ['post_id' => $post->id],
            mailable: new PostPublishFailed($post),
        );
    }
}

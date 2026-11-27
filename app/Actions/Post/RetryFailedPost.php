<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\PublishStatus;
use App\Enums\Post\RetryOutcome;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Exceptions\Social\ErrorCategory;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\ThreadProgress;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Queue a new publish attempt for a failed post, resuming an in-flight remote
 * publish when a checkpoint exists. The backoffice's Retry button calls it.
 */
class RetryFailedPost
{
    public function __construct(
        private readonly TikTokPhotoDerivativeCleaner $tiktokPhotoDerivativeCleaner,
    ) {}

    public function isRetryable(Post $post): bool
    {
        return $post->status === PostStatus::Failed
            && $post->publish_status === PublishStatus::Failed
            && $post->hasChannel();
    }

    /**
     * The Instagram first comment may already be live: whoever retries must
     * check Instagram and confirm the comment is missing first.
     */
    public function needsInstagramConfirmation(Post $post): bool
    {
        return PublishCheckpoint::instagramCommentIsUnconfirmed($post->error_context);
    }

    public function execute(Post $post, bool $instagramCommentChecked = false): RetryOutcome
    {
        if (! $this->isRetryable($post)) {
            return RetryOutcome::NotRetryable;
        }

        if ($post->isOwnerBlocked()) {
            return RetryOutcome::OwnerBlocked;
        }

        $previousContext = $post->error_context;

        if ($this->needsInstagramConfirmation($post)) {
            if (! ThreadProgress::hasPublishedRoot($previousContext)) {
                return RetryOutcome::CommentUnconfirmed;
            }

            if (! $instagramCommentChecked) {
                return RetryOutcome::NeedsInstagramConfirmation;
            }
        }

        if (! $this->prepareRetry($post, $this->contextForRetry($previousContext))) {
            return RetryOutcome::Changed;
        }

        $post->refresh();

        if ($post->platform === SocialPlatform::TikTok && blank(PublishCheckpoint::tiktokPublishId($post->error_context))) {
            $this->tiktokPhotoDerivativeCleaner->cleanup($previousContext, $post->id);
        }

        PublishToSocialPlatform::dispatch($post);

        return RetryOutcome::Queued;
    }

    /**
     * @param  array<string, mixed>|null  $retryContext
     */
    private function prepareRetry(Post $post, ?array $retryContext): bool
    {
        return DB::transaction(function () use ($post, $retryContext): bool {
            $locked = Post::query()->lockForUpdate()->find($post->id);

            if (! $locked || ! $this->isRetryable($locked)) {
                return false;
            }

            if ($locked->error_context !== $post->error_context) {
                return false;
            }

            $locked->writePublication([
                'publish_status' => PublishStatus::Pending,
                'platform_post_id' => null,
                'platform_url' => null,
                'error_message' => null,
                'error_context' => $retryContext,
                'published_at' => null,
            ]);
            $locked->update(['status' => PostStatus::Publishing]);

            return true;
        });
    }

    /**
     * Keep in-flight checkpoints only. Confirmed remote failures must start over,
     * except the live segments of a thread, which are never posted twice.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>|null
     */
    private function contextForRetry(?array $context): ?array
    {
        $thread = data_get($context, ThreadProgress::KEY);
        $threadProgress = is_array($thread) ? $thread : [];
        $canResumeRemote = ErrorCategory::tryFromContext($context)?->isResumable() === true;
        $tiktokPublishId = $canResumeRemote ? PublishCheckpoint::tiktokPublishId($context) : null;
        $instagramWorkflow = $canResumeRemote ? PublishCheckpoint::instagramWorkflow($context) : null;
        $tiktokPaths = $tiktokPublishId === null ? [] : PublishCheckpoint::tiktokDerivativePaths($context);

        $checkpoints = Arr::whereNotNull([
            ThreadProgress::KEY => filled($threadProgress) ? $threadProgress : null,
            PublishCheckpoint::TIKTOK_PUBLISH_ID => $tiktokPublishId,
            PublishCheckpoint::INSTAGRAM_WORKFLOW => $instagramWorkflow,
            PublishCheckpoint::TIKTOK_DERIVATIVE_PATHS => filled($tiktokPaths) ? $tiktokPaths : null,
        ]);

        return filled($checkpoints) ? $checkpoints : null;
    }
}

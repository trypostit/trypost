<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Exceptions\Social\ErrorCategory;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\ThreadProgress;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryFailedPost extends Command
{
    protected $signature = 'posts:retry
        {post : ID of the failed post to retry}';

    protected $description = 'Retry a failed post, resuming an in-flight remote publish when a checkpoint exists';

    public function __construct(
        private readonly TikTokPhotoDerivativeCleaner $tiktokPhotoDerivativeCleaner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $post = Post::query()->with('socialAccount')->find((string) $this->argument('post'));

        if (! $post) {
            $this->error('Post not found.');

            return self::FAILURE;
        }

        if (! $this->isRetryable($post)) {
            $this->error('Only failed posts with a channel can be retried.');

            return self::FAILURE;
        }

        $commentWasPending = PublishCheckpoint::instagramCommentIsUnconfirmed($post->error_context);

        if ($commentWasPending && ! ThreadProgress::hasPublishedRoot($post->error_context)) {
            $this->error(__('posts.errors.instagram.comment_media_unconfirmed'));

            return self::FAILURE;
        }

        $this->table(
            ['Post ID', 'Platform', 'Account', 'Last error', 'Mode'],
            [[
                $post->id,
                $post->platform->value,
                $post->display_username ?? '—',
                $post->error_message ?? '—',
                $this->resumableContext($post->error_context) === null ? 'New' : 'Resume',
            ]],
        );

        $needsCommentConfirmation = $commentWasPending && blank(data_get($post->error_context, ThreadProgress::KEY.'.1.id'));
        $confirmation = match ($needsCommentConfirmation) {
            true => 'Did you check Instagram and confirm the first comment is missing?',
            false => 'Queue a publish attempt for this post?',
        };

        if (! $this->confirm($confirmation)) {
            return $needsCommentConfirmation ? self::FAILURE : self::SUCCESS;
        }

        $originalContext = $this->prepareRetry($post, $needsCommentConfirmation);

        if ($originalContext === false) {
            $this->warn('The post changed while the command was running; nothing was retried.');

            return self::FAILURE;
        }

        $post->refresh();

        if ($post->platform === SocialPlatform::TikTok && blank(PublishCheckpoint::tiktokPublishId($post->error_context))) {
            $this->tiktokPhotoDerivativeCleaner->cleanup($originalContext, $post->id);
        }

        PublishToSocialPlatform::dispatch($post);

        Log::info('Failed post queued for manual retry', ['post_id' => $post->id]);

        return self::SUCCESS;
    }

    private function isRetryable(Post $post): bool
    {
        return $post->status === PostStatus::Failed
            && $post->publish_status === PublishStatus::Failed
            && $post->hasChannel();
    }

    /**
     * Returns the error context the failure left, or false when the post is
     * no longer retryable.
     *
     * @return array<string, mixed>|null|false
     */
    private function prepareRetry(Post $post, bool $confirmedCommentMissing): array|null|false
    {
        return DB::transaction(function () use ($post, $confirmedCommentMissing): array|null|false {
            $locked = Post::query()->lockForUpdate()->find($post->id);

            if (! $locked || ! $this->isRetryable($locked)) {
                return false;
            }

            $originalContext = $locked->error_context;
            $commentWasPending = PublishCheckpoint::instagramCommentIsUnconfirmed($originalContext);

            if (($confirmedCommentMissing || $commentWasPending) && $originalContext !== $post->error_context) {
                return false;
            }

            $retryContext = $this->resumableContext($originalContext);
            if ($commentWasPending && ! $confirmedCommentMissing) {
                $retryContext = [...($retryContext ?? []), PublishCheckpoint::INSTAGRAM_COMMENT_PENDING => true];
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

            return $originalContext;
        });
    }

    /**
     * Keep in-flight checkpoints only. Confirmed remote failures must start over,
     * except the live segments of a thread, which are never posted twice.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>|null
     */
    private function resumableContext(?array $context): ?array
    {
        $kept = [];
        $thread = data_get($context, ThreadProgress::KEY);

        if (is_array($thread) && $thread !== []) {
            $kept[ThreadProgress::KEY] = $thread;
        }

        if (ErrorCategory::tryFromContext($context)?->isResumable() !== true) {
            return $kept === [] ? null : $kept;
        }

        $publishId = PublishCheckpoint::tiktokPublishId($context);
        $workflow = PublishCheckpoint::instagramWorkflow($context);

        if ($publishId !== null) {
            $kept[PublishCheckpoint::TIKTOK_PUBLISH_ID] = $publishId;
            $paths = PublishCheckpoint::tiktokDerivativePaths($context);

            if ($paths !== []) {
                $kept[PublishCheckpoint::TIKTOK_DERIVATIVE_PATHS] = $paths;
            }
        }

        if ($workflow !== null) {
            $kept[PublishCheckpoint::INSTAGRAM_WORKFLOW] = $workflow;
        }

        return $kept === [] ? null : $kept;
    }
}

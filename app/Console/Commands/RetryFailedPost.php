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
use Illuminate\Support\Arr;
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

        $previousContext = $post->error_context;
        $commentWasPending = PublishCheckpoint::instagramCommentIsUnconfirmed($previousContext);

        if ($commentWasPending && ! ThreadProgress::hasPublishedRoot($previousContext)) {
            $this->error(__('posts.errors.instagram.comment_media_unconfirmed'));

            return self::FAILURE;
        }

        $needsCommentConfirmation = $commentWasPending && blank(data_get($previousContext, ThreadProgress::KEY.'.1.id'));
        $retryContext = $this->contextForRetry(
            $previousContext,
            keepPendingComment: $commentWasPending && ! $needsCommentConfirmation,
        );

        $this->table(
            ['Post ID', 'Platform', 'Account', 'Last error', 'Mode'],
            [[
                $post->id,
                $post->platform->value,
                $post->display_username ?? '—',
                $post->error_message ?? '—',
                filled($retryContext) ? 'Resume' : 'New',
            ]],
        );

        $confirmation = match ($needsCommentConfirmation) {
            true => 'Did you check Instagram and confirm the first comment is missing?',
            false => 'Queue a publish attempt for this post?',
        };

        if (! $this->confirm($confirmation)) {
            return $needsCommentConfirmation ? self::FAILURE : self::SUCCESS;
        }

        if (! $this->prepareRetry($post, $retryContext)) {
            $this->warn('The post changed while the command was running; nothing was retried.');

            return self::FAILURE;
        }

        $post->refresh();

        if ($post->platform === SocialPlatform::TikTok && blank(PublishCheckpoint::tiktokPublishId($post->error_context))) {
            $this->tiktokPhotoDerivativeCleaner->cleanup($previousContext, $post->id);
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
    private function contextForRetry(?array $context, bool $keepPendingComment): ?array
    {
        $kept = [];
        $thread = data_get($context, ThreadProgress::KEY);

        if (is_array($thread) && filled($thread)) {
            $kept[ThreadProgress::KEY] = $thread;
        }

        if (ErrorCategory::tryFromContext($context)?->isResumable() === true) {
            $publishId = PublishCheckpoint::tiktokPublishId($context);
            $kept = [
                ...$kept,
                ...Arr::whereNotNull([
                    PublishCheckpoint::TIKTOK_PUBLISH_ID => $publishId,
                    PublishCheckpoint::INSTAGRAM_WORKFLOW => PublishCheckpoint::instagramWorkflow($context),
                ]),
            ];

            if ($publishId !== null) {
                $paths = PublishCheckpoint::tiktokDerivativePaths($context);
                if (filled($paths)) {
                    $kept[PublishCheckpoint::TIKTOK_DERIVATIVE_PATHS] = $paths;
                }
            }
        }

        if ($keepPendingComment) {
            $kept[PublishCheckpoint::INSTAGRAM_COMMENT_PENDING] = true;
        }

        return filled($kept) ? $kept : null;
    }
}

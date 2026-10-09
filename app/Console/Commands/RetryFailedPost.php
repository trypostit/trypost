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

        if (! $this->confirm('Queue a publish attempt for this post?')) {
            return self::SUCCESS;
        }

        $originalContext = $this->prepareRetry($post);

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
    private function prepareRetry(Post $post): array|null|false
    {
        return DB::transaction(function () use ($post): array|null|false {
            $locked = Post::query()->lockForUpdate()->find($post->id);

            if (! $locked || ! $this->isRetryable($locked)) {
                return false;
            }

            $originalContext = $locked->error_context;

            $locked->writePublication([
                'publish_status' => PublishStatus::Pending,
                'platform_post_id' => null,
                'platform_url' => null,
                'error_message' => null,
                'error_context' => $this->resumableContext($originalContext),
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

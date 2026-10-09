<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Support\Social\LimitRetryPolicy;
use Illuminate\Console\Command;
use Throwable;

class ProcessScheduledPosts extends Command
{
    protected $signature = 'posts:process-scheduled';

    protected $description = 'Process scheduled posts and network limit retries that are due for publishing';

    public function handle(): void
    {
        Post::query()
            ->due()
            ->each(function (Post $post) {
                try {
                    $claimed = Post::where('id', $post->id)
                        ->where('status', PostStatus::Scheduled)
                        ->update(['status' => PostStatus::Publishing]);

                    if ($claimed) {
                        PublishPost::dispatch($post);
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        $this->dispatchDueLimitRetries();
    }

    /**
     * Posts a network refused for a limit wait in the database, not in a
     * delayed job, so a worker restart never loses them. Clearing retry_at
     * claims the post, so each retry is dispatched once.
     */
    private function dispatchDueLimitRetries(): void
    {
        Post::query()
            ->dueForLimitRetry()
            ->each(function (Post $post): void {
                try {
                    $claimed = Post::query()
                        ->whereKey($post->id)
                        ->dueForLimitRetry()
                        ->toBase()
                        ->update(['retry_at' => null, 'publication_updated_at' => now()]);

                    if ($claimed) {
                        PublishToSocialPlatform::dispatch(
                            $post,
                            LimitRetryPolicy::UNIQUE_ATTEMPT_OFFSET + LimitRetryPolicy::retriesSoFar($post->error_context),
                        );
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }
}

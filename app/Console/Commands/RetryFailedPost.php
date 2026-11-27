<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Post\RetryFailedPost as RetryFailedPostAction;
use App\Enums\Post\RetryOutcome;
use App\Models\Post;
use App\Support\Social\ThreadProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RetryFailedPost extends Command
{
    protected $signature = 'posts:retry
        {post : ID of the failed post to retry}';

    protected $description = 'Retry a failed post, resuming an in-flight remote publish when a checkpoint exists';

    public function __construct(
        private readonly RetryFailedPostAction $retryFailedPost,
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

        if (! $this->retryFailedPost->isRetryable($post)) {
            $this->error('Only failed posts with a channel can be retried.');

            return self::FAILURE;
        }

        $commentUnconfirmed = $this->retryFailedPost->needsInstagramConfirmation($post);

        if ($commentUnconfirmed && ! ThreadProgress::hasPublishedRoot($post->error_context)) {
            $this->error(__('posts.errors.instagram.comment_unconfirmed'));

            return self::FAILURE;
        }

        $this->showRetrySummary($post, resuming: $this->retryFailedPost->resumes($post));

        $confirmation = match ($commentUnconfirmed) {
            true => 'Did you check Instagram and confirm the first comment is missing?',
            false => 'Queue a publish attempt for this post?',
        };

        if (! $this->confirm($confirmation)) {
            return $commentUnconfirmed ? self::FAILURE : self::SUCCESS;
        }

        $outcome = $this->retryFailedPost->execute($post, instagramCommentChecked: true);

        if ($outcome !== RetryOutcome::Queued) {
            $this->warn('The post changed while the command was running; nothing was retried.');

            return self::FAILURE;
        }

        Log::info('Failed post queued for manual retry', ['post_id' => $post->id]);

        return self::SUCCESS;
    }

    private function showRetrySummary(Post $post, bool $resuming): void
    {
        $this->table(
            ['Post ID', 'Platform', 'Account', 'Last error', 'Mode'],
            [[
                $post->id,
                $post->platform->value,
                $post->display_username ?? '—',
                $post->error_message ?? '—',
                $resuming ? 'Resume' : 'New',
            ]],
        );
    }
}

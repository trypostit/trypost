<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Post\Origin;
use App\Enums\PostHog\PostEvent;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackPostFailed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $postId)
    {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $post = Post::query()
            ->with(['workspace.account.plan'])
            ->find($this->postId);

        $account = $post?->workspace?->account;

        if (blank($account) || ! self::counts($post)) {
            return;
        }

        $postHog->capture(
            (string) $post->user_id,
            PostEvent::Failed->value,
            [
                'post_id' => (string) $post->id,
                'post_group_id' => $post->post_group_id,
                'platform' => $post->platform?->value,
                'network' => $post->platform?->network(),
                'created_via' => $post->created_via?->value,
                'error_type' => data_get($post->error_context, 'category', ErrorCategory::Unknown->value),
            ],
            $account,
        );
    }

    /**
     * Only posts written in TryPost by a user count: an imported network post
     * (or any post without an author) never feeds a PostHog metric.
     */
    private static function counts(Post $post): bool
    {
        return filled($post->user_id) && $post->origin === Origin::TryPost;
    }
}

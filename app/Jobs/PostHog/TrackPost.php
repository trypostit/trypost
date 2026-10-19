<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Post\Origin;
use App\Enums\PostHog\PostEvent;
use App\Models\Post;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackPost implements ShouldQueue
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

        if (! $post || ! self::counts($post)) {
            return;
        }

        $account = $post->workspace?->account;

        if (! $account) {
            return;
        }

        $postHog->capture(
            (string) $post->user_id,
            PostEvent::Created->value,
            [
                'post_id' => (string) $post->id,
                'workspace_id' => (string) $post->workspace_id,
                'created_via' => $post->created_via?->value,
                'status' => $post->status->value,
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

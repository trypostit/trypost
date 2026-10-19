<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Post\Status;
use App\Enums\PostHog\PostEvent;
use App\Models\Post;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackPostGroupPublished implements ShouldQueue
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

        if ($account === null) {
            return;
        }

        $publishedPosts = $this->groupQuery($post)
            ->where('status', Status::Published)
            ->orderBy('published_at')
            ->orderBy('id')
            ->get();

        if ((string) $publishedPosts->first()?->id !== (string) $post->id) {
            return;
        }

        $distinctId = (string) ($post->user_id ?? $account->owner_id);

        if ($distinctId === '') {
            return;
        }

        $publishedBefore = Post::query()
            ->createdInTryPost()
            ->where('status', Status::Published)
            ->whereIn('workspace_id', $account->workspaces()->select('id'))
            ->whereNotIn('id', $this->groupQuery($post)->select('id'))
            ->exists();

        $postHog->capture(
            $distinctId,
            PostEvent::Published->value,
            [
                'post_group_id' => filled($post->post_group_id) ? (string) $post->post_group_id : null,
                'created_via' => $post->created_via?->value,
                'platforms' => $publishedPosts
                    ->map(fn (Post $published): ?string => $published->platform?->network())
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'channels_count' => $this->groupQuery($post)->count(),
                'is_first' => ! $publishedBefore,
            ],
            $account,
        );
    }

    /**
     * @return Builder<Post>
     */
    private function groupQuery(Post $post): Builder
    {
        return filled($post->post_group_id)
            ? Post::query()->where('post_group_id', $post->post_group_id)
            : Post::query()->whereKey($post->id);
    }
}

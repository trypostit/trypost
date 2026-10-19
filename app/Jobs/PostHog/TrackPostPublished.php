<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\PostEvent;
use App\Models\Post;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class TrackPostPublished implements ShouldQueue
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

        if (blank($account)) {
            return;
        }

        $distinctId = (string) ($post->user_id ?? $account->owner_id);

        if (blank($distinctId)) {
            return;
        }

        $publishedBefore = Post::query()
            ->createdInTryPost()
            ->published()
            ->whereIn('workspace_id', $account->workspaces()->select('id'))
            ->whereNotIn('id', $this->groupQuery($post)->select('id'))
            ->exists();

        $groupPosts = $this->groupQuery($post)->get(['id', 'platform']);

        $groupKey = filled($post->post_group_id) ? $post->post_group_id : $post->id;

        if (! Cache::add("posthog:post_group_published:{$groupKey}", true, now()->addDays(90))) {
            return;
        }

        $postHog->capture(
            $distinctId,
            PostEvent::Published->value,
            [
                'post_group_id' => filled($post->post_group_id) ? (string) $post->post_group_id : null,
                'created_via' => $post->created_via?->value,
                'platforms' => $groupPosts
                    ->map(fn (Post $groupPost): ?string => $groupPost->platform?->network())
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all(),
                'channels_count' => $groupPosts->count(),
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

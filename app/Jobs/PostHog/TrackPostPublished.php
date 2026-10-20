<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\PostEvent;
use App\Models\Account;
use App\Models\Post;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
            ->createdInTryPost()
            ->whereNotNull('user_id')
            ->with(['workspace.account.plan'])
            ->find($this->postId);

        $account = $post?->workspace?->account;

        if (blank($account)) {
            return;
        }

        $groupPosts = filled($post->post_group_id)
            ? $post->groupPosts()->get(['id', 'platform', 'published_at'])
            : new Collection([$post]);

        $publishedBefore = $this->publishedBefore($account, $groupPosts);

        $groupKey = $post->post_group_id ?: $post->id;

        if (! Cache::add("posthog:post_group_published:{$groupKey}", true, now()->addDays(90))) {
            return;
        }

        $postHog->capture(
            (string) $post->user_id,
            PostEvent::Published->value,
            [
                'post_group_id' => $post->post_group_id,
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
     * Whether the account published in TryPost before this group, decided by
     * time (then id) rather than by status: two groups settling together
     * would each see the other already published.
     *
     * @param  Collection<int, Post>  $groupPosts
     */
    private function publishedBefore(Account $account, Collection $groupPosts): bool
    {
        $first = $groupPosts
            ->filter(fn (Post $groupPost): bool => filled($groupPost->published_at))
            ->sortBy([['published_at', 'asc'], ['id', 'asc']])
            ->first();

        if (blank($first)) {
            return true;
        }

        return $account->posts()
            ->createdInTryPost()
            ->published()
            ->where(fn (Builder $query) => $query
                ->where('posts.published_at', '<', $first->published_at)
                ->orWhere(fn (Builder $tied) => $tied
                    ->where('posts.published_at', $first->published_at)
                    ->where('posts.id', '<', $first->id)))
            ->exists();
    }
}

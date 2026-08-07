<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\Automation\Trigger\Type as TriggerType;
use App\Enums\Post\Status as PostStatus;
use App\Events\OnboardingStatusUpdated;
use App\Events\PostCreated;
use App\Jobs\Automation\DispatchPostTriggerAutomationsJob;
use App\Models\Account;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PostObserver
{
    public function created(Post $post): void
    {
        DB::afterCommit(fn () => PostCreated::dispatch($post));

        $this->notifyOnboarding($post);
    }

    public function deleted(Post $post): void
    {
        $this->notifyOnboarding($post);
    }

    public function saved(Post $post): void
    {
        if (! $post->wasChanged('status')) {
            return;
        }

        $triggerType = match ($post->status) {
            PostStatus::Published => TriggerType::PostPublished,
            PostStatus::Scheduled => TriggerType::PostScheduled,
            default => null,
        };

        if ($triggerType !== null) {
            DispatchPostTriggerAutomationsJob::dispatch($post, $triggerType)->afterCommit();
        }
    }

    /**
     * Notify only on the account's first post create or last post delete —
     * later creates / partial deletes would spam Echo while activation is open.
     */
    private function notifyOnboarding(Post $post): void
    {
        $account = $post->workspace?->account;

        if ($account?->isOnboardingOpen() && $this->otherPosts($account, $post)->doesntExist()) {
            OnboardingStatusUpdated::dispatchForWorkspace($post->workspace_id, $post->user);
        }
    }

    /**
     * @return Builder<Post>
     */
    private function otherPosts(Account $account, Post $post): Builder
    {
        return Post::query()
            ->whereIn('workspace_id', $account->workspaces()->select('id'))
            ->whereKeyNot($post->id);
    }
}

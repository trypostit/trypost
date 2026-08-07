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
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PostObserver
{
    public function created(Post $post): void
    {
        DB::afterCommit(fn () => PostCreated::dispatch($post));

        $this->notifyOnboarding($post, function (Account $account) use ($post): bool {
            // Only the account's first post unlocks the step — later creates
            // would just spam Echo reloads while activation is still open.
            return Post::query()
                ->whereIn('workspace_id', $account->workspaces()->select('id'))
                ->whereKeyNot($post->id)
                ->doesntExist();
        });
    }

    public function deleted(Post $post): void
    {
        $this->notifyOnboarding($post, function (Account $account): bool {
            // The step only flips when the account's last post disappears.
            return Post::query()
                ->whereIn('workspace_id', $account->workspaces()->select('id'))
                ->doesntExist();
        });
    }

    /**
     * @param  callable(Account): bool  $shouldNotify
     */
    private function notifyOnboarding(Post $post, callable $shouldNotify): void
    {
        $account = $post->workspace?->account;

        if ($account?->isOnboardingOpen() && $shouldNotify($account)) {
            OnboardingStatusUpdated::dispatchForWorkspace(
                $post->workspace_id,
                $this->actorFor($post),
            );
        }
    }

    /**
     * Prefer the authenticated request user (may carry an in-memory API/MCP
     * workspace) over the persisted post author for checklist sync.
     */
    private function actorFor(Post $post): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->belongsToAccount($post->workspace?->account_id)
            ? $user
            : $post->user;
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
}

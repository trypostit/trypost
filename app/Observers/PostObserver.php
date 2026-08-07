<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\Automation\Trigger\Type as TriggerType;
use App\Enums\Post\Status as PostStatus;
use App\Events\OnboardingStatusUpdated;
use App\Events\PostCreated;
use App\Jobs\Automation\DispatchPostTriggerAutomationsJob;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PostObserver
{
    public function created(Post $post): void
    {
        DB::afterCommit(fn () => PostCreated::dispatch($post));

        $account = $post->workspace?->account;

        if ($account === null || $account->hasFinishedOnboarding()) {
            return;
        }

        // Only the account's first post unlocks the onboarding step — later
        // creates would just spam Echo reloads while activation is still open.
        $isFirstPost = Post::query()
            ->whereIn('workspace_id', $account->workspaces()->select('id'))
            ->whereKeyNot($post->id)
            ->doesntExist();

        if ($isFirstPost) {
            OnboardingStatusUpdated::dispatchForWorkspace(
                $post->workspace_id,
                $this->actorFor($post),
            );
        }
    }

    public function deleted(Post $post): void
    {
        $account = $post->workspace?->account;

        if ($account === null || $account->hasFinishedOnboarding()) {
            return;
        }

        // The step only flips when the account's last post disappears.
        $accountHasPosts = Post::query()
            ->whereIn('workspace_id', $account->workspaces()->select('id'))
            ->exists();

        if (! $accountHasPosts) {
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

        if ($user instanceof User
            && (string) $user->account_id === (string) $post->workspace?->account_id
        ) {
            return $user;
        }

        return $post->user;
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

        if ($triggerType === null) {
            return;
        }

        DispatchPostTriggerAutomationsJob::dispatch($post, $triggerType)->afterCommit();
    }
}

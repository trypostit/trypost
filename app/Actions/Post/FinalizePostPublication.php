<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Jobs\SendNotification;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Support\Facades\DB;

/**
 * Settles a post once every enabled target has reached a terminal state, and
 * notifies the owner once. Shared by PublishToSocialPlatform, PublishPost::failed,
 * RecoverStuckPosts, and ReconcileGoogleBusinessPost.
 */
class FinalizePostPublication
{
    public function handle(Post $post): void
    {
        /** @var array{post: Post, successful: bool}|null $outcome */
        $outcome = DB::transaction(function () use ($post): ?array {
            $post = Post::query()
                ->with(['workspace.owner', 'postPlatforms.socialAccount'])
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if (! $post instanceof Post || $post->status->isSettled()) {
                return null;
            }

            $targets = $post->postPlatforms->where('enabled', true);

            if ($targets->isEmpty()) {
                if ($post->status !== PostStatus::Publishing) {
                    return null;
                }

                $post->markAsFailed();

                return ['post' => $post, 'successful' => false];
            }

            $finished = $targets->filter(fn (PostPlatform $target): bool => $target->status->isFinished());
            $published = $finished->where('status', PostPlatformStatus::Published);
            $failed = $finished->reject(fn (PostPlatform $target): bool => $target->status === PostPlatformStatus::Published);

            if ($finished->count() < $targets->count()) {
                return null;
            }

            $successful = $failed->isEmpty();

            if ($successful) {
                $post->markAsPublished();
            } elseif ($published->isNotEmpty()) {
                $post->markAsPartiallyPublished();
            } else {
                $post->markAsFailed();
            }

            ScheduleNextOccurrence::execute($post);

            return ['post' => $post, 'successful' => $successful];
        });

        if ($outcome === null) {
            return;
        }

        $this->notify($outcome['post'], $outcome['successful']);
    }

    private function notify(Post $post, bool $successful): void
    {
        $owner = $post->workspace->owner;

        if (! $owner) {
            return;
        }

        SendNotification::dispatch(
            user: $owner,
            type: $successful ? Type::PostPublished : Type::PostFailed,
            mailable: $successful ? new PostPublished($post) : new PostPublishFailed($post),
        );
    }
}

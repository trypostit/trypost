<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Notification\Type;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\SendNotification;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Settles a post once its publication has reached a terminal state, and
 * notifies the workspace once. Shared by PublishToSocialPlatform, PublishPost::failed,
 * RecoverStuckPosts, and ReconcileGoogleBusinessPost.
 */
class FinalizePostPublication
{
    public function handle(Post $post): void
    {
        /** @var array{post: Post, successful: bool}|null $outcome */
        $outcome = DB::transaction(function () use ($post): ?array {
            $post = Post::query()
                ->with(['workspace', 'socialAccount'])
                ->whereKey($post->id)
                ->lockForUpdate()
                ->first();

            if (! $post instanceof Post || $post->status->isSettled()) {
                return null;
            }

            if (! $post->hasChannel() && $post->publish_status->isInFlight()) {
                if ($post->status !== PostStatus::Publishing) {
                    return null;
                }

                $post->markPublicationFailed(__('posts.errors.choose_channel'));
                $post->markAsFailed();

                return ['post' => $post, 'successful' => false];
            }

            if (! $post->publish_status->isFinished()) {
                return null;
            }

            $successful = $post->publish_status === PublishStatus::Published;

            if ($successful) {
                $post->markAsPublished();
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

    /**
     * Emails every member of the workspace (the owner is always one); each
     * one's notification preferences decide whether it is sent.
     */
    private function notify(Post $post, bool $successful): void
    {
        $post->workspace->members()->get()
            ->each(fn (User $member) => SendNotification::dispatch(
                user: $member,
                type: $successful ? Type::PostPublished : Type::PostFailed,
                mailable: $successful ? new PostPublished($post) : new PostPublishFailed($post),
            ));
    }
}

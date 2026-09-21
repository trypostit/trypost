<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Jobs\SendNotification;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Support\Collection;
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
        /** @var array{post: Post, successful: bool, platforms: Collection<int, PostPlatform>}|null $outcome */
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
                return null;
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

            return [
                'post' => $post,
                'successful' => $successful,
                'platforms' => $successful ? $published : $failed,
            ];
        });

        if ($outcome === null) {
            return;
        }

        $this->notify($outcome['post'], $outcome['successful'], $outcome['platforms']);
    }

    /**
     * @param  Collection<int, PostPlatform>  $platforms
     */
    private function notify(Post $post, bool $successful, Collection $platforms): void
    {
        $owner = $post->workspace->owner;

        if (! $owner) {
            return;
        }

        $type = $successful ? Type::PostPublished : Type::PostFailed;
        $locale = $owner->preferredLocale();

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $post->workspace_id,
            type: $type,
            channel: Channel::Both,
            title: __("notifications.{$type->value}.title", [], $locale),
            body: __("notifications.{$type->value}.body", [
                'platforms' => $platforms->map->notificationLabel()->implode(', '),
            ], $locale),
            data: ['post_id' => $post->id],
            mailable: $successful ? new PostPublished($post) : new PostPublishFailed($post),
        );
    }
}

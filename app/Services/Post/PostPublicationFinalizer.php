<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Jobs\SendNotification;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Support\Facades\DB;

class PostPublicationFinalizer
{
    public function finalize(PostPlatform $postPlatform): void
    {
        $result = DB::transaction(function () use ($postPlatform): ?array {
            $post = Post::query()->lockForUpdate()->findOrFail($postPlatform->post_id);
            $enabled = $post->postPlatforms()->enabled()->get();
            $total = $enabled->count();

            if ($total === 0) {
                return null;
            }

            $published = $enabled->where('status', PostPlatformStatus::Published)->count();
            $failed = $enabled->whereIn('status', [PostPlatformStatus::Failed, PostPlatformStatus::Rejected])->count();

            if (($published + $failed) < $total) {
                return null;
            }

            $targetStatus = match (true) {
                $published === $total => PostStatus::Published,
                $published > 0 => PostStatus::PartiallyPublished,
                default => PostStatus::Failed,
            };

            if ($post->status === $targetStatus) {
                return null;
            }

            match ($targetStatus) {
                PostStatus::Published => $post->markAsPublished(),
                PostStatus::PartiallyPublished => $post->markAsPartiallyPublished(),
                PostStatus::Failed => $post->markAsFailed(),
                default => null,
            };

            return [$post->fresh(), $targetStatus === PostStatus::Published];
        });

        if ($result === null) {
            return;
        }

        [$post, $successful] = $result;
        $this->notify($post, $successful);
    }

    private function notify(Post $post, bool $successful): void
    {
        $owner = $post->workspace->owner;

        if (! $owner) {
            return;
        }

        $statuses = $successful
            ? [PostPlatformStatus::Published]
            : [PostPlatformStatus::Failed, PostPlatformStatus::Rejected];
        $platforms = $post->postPlatforms()
            ->with('socialAccount')
            ->enabled()
            ->whereIn('status', $statuses)
            ->get()
            ->map(function (PostPlatform $target): string {
                $username = filled($target->display_username) ? " (@{$target->display_username})" : '';

                return $target->display_name.$username;
            })
            ->implode(', ');

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $post->workspace_id,
            type: $successful ? Type::PostPublished : Type::PostFailed,
            channel: Channel::Both,
            title: $successful ? 'Post published successfully' : 'Post failed to publish',
            body: $successful ? $platforms : "Failed on: {$platforms}",
            data: ['post_id' => $post->id],
            mailable: $successful ? new PostPublished($post) : new PostPublishFailed($post),
        );
    }
}

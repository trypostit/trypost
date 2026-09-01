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

/**
 * Settles a post once every enabled target has reached a terminal state, and
 * notifies the owner once. Shared by the publish job and by the Google Business
 * reconciliation job, which finishes targets the publish job had to leave open.
 */
class FinalizePostPublication
{
    /**
     * Targets that are done, whichever way they went.
     *
     * @var array<int, PostPlatformStatus>
     */
    private const FAILURE_STATUSES = [
        PostPlatformStatus::Failed,
        PostPlatformStatus::Rejected,
    ];

    public function handle(PostPlatform $postPlatform): void
    {
        $post = $postPlatform->post->fresh();
        $enabledPlatforms = $post->postPlatforms->where('enabled', true);

        $total = $enabledPlatforms->count();
        $publishedCount = $enabledPlatforms->where('status', PostPlatformStatus::Published)->count();
        $failedCount = $enabledPlatforms->whereIn('status', self::FAILURE_STATUSES)->count();

        if ($publishedCount + $failedCount < $total) {
            return;
        }

        if ($publishedCount === $total) {
            $post->markAsPublished();
            $this->notify($post, true);

            return;
        }

        if ($publishedCount > 0) {
            $post->markAsPartiallyPublished();
        } else {
            $post->markAsFailed();
        }

        $this->notify($post, false);
    }

    private function notify(Post $post, bool $successful): void
    {
        $owner = $post->workspace->owner;

        if (! $owner) {
            return;
        }

        $platforms = $post->postPlatforms()
            ->with('socialAccount')
            ->enabled()
            ->when(
                $successful,
                fn ($query) => $query->where('status', PostPlatformStatus::Published),
                fn ($query) => $query->whereIn('status', self::FAILURE_STATUSES),
            )
            ->get()
            ->map(fn (PostPlatform $pp): string => $pp->platform->label().' (@'.data_get($pp, 'socialAccount.username', '').')')
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

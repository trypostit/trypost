<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type as NotificationType;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\SendNotification;
use App\Mail\PostReadyForManualPublish;
use App\Models\Post;
use Illuminate\Console\Command;

class ProcessScheduledPosts extends Command
{
    protected $signature = 'posts:process-scheduled';

    protected $description = 'Process scheduled posts that are due for publishing';

    public function handle(): void
    {
        // Auto-publish: claim due auto posts and dispatch the publisher.
        Post::query()
            ->dueForAutoPublish()
            ->each(function (Post $post) {
                // Atomically claim the post — only dispatch if we successfully change its status
                $claimed = Post::where('id', $post->id)
                    ->where('status', PostStatus::Scheduled)
                    ->update(['status' => PostStatus::Publishing]);

                if ($claimed) {
                    PublishPost::dispatch($post);
                }
            });

        // Manual (notify-only): claim the one-time notification so a due manual
        // post reminds the owner to publish it from the native app, and never
        // auto-publishes.
        Post::query()
            ->with(['workspace.owner'])
            ->manualDueNotNotified()
            ->each(function (Post $post) {
                $owner = $post->workspace?->owner;

                if (! $owner) {
                    $post->markManualPublishNotified();

                    return;
                }

                // Atomically claim the notification (null guard) so a post that
                // stays scheduled-notified isn't re-notified every minute.
                $claimed = Post::where('id', $post->id)
                    ->whereNull('manual_publish_notified_at')
                    ->update(['manual_publish_notified_at' => now()]);

                if ($claimed) {
                    SendNotification::dispatch(
                        user: $owner,
                        workspaceId: $post->workspace_id,
                        type: NotificationType::PostManualPublishDue,
                        channel: Channel::Both,
                        title: trans('notifications.post_manual_publish_due.title', [], $post->workspace?->content_language),
                        body: trans('notifications.post_manual_publish_due.body', ['caption' => mb_strimwidth($post->content, 0, 120, '…')], $post->workspace?->content_language),
                        data: ['post_id' => $post->id],
                        mailable: new PostReadyForManualPublish($post),
                    );
                }
            });
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Repurpose;

use App\Actions\Post\CreatePost;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Services\Post\MediaAttacher;
use App\Services\Repurpose\CaptionAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns one video published outside TryPost into posts on the configured
 * destinations.
 *
 * A post carries a single caption that every publisher reads, so one post is
 * created per destination instead of one post with many platforms. That keeps
 * each caption adapted to its own network — a Reel keeps its 2,200 characters
 * even when a YouTube Short in the same repurpose is capped at 100 — without
 * touching the publishing core. The video itself is downloaded once and the
 * same stored file is shared by every post.
 */
class ProcessRepurposeItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public RepurposeItem $item,
        public string $downloadUrl,
        public string $caption,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(MediaAttacher $media, CaptionAdapter $captions): void
    {
        if ($this->item->status->isTerminal() || $this->item->posts()->exists()) {
            return;
        }

        $repurpose = $this->item->repurpose;
        $workspace = $repurpose->workspace;
        $user = $repurpose->user;

        $this->item->update(['status' => ItemStatus::Processing]);

        $posts = [];
        $snapshot = null;

        foreach ($repurpose->destinations as $destination) {
            $account = SocialAccount::find(data_get($destination, 'social_account_id'));

            if ($account === null || $account->disconnected_at !== null) {
                continue;
            }

            $post = CreatePost::execute($workspace, $user, [
                'content' => $captions->adapt($workspace, $user, $this->caption, $account->platform, null),
                'created_via' => CreatedVia::Repurpose,
                'platforms' => [$destination],
            ]);

            $post->update(['repurpose_item_id' => $this->item->id]);

            if ($snapshot === null) {
                $snapshot = data_get($media->attachFromUrls($post, [['url' => $this->downloadUrl]]), 'attached', []);

                if ($snapshot === []) {
                    $this->discard($posts + [$post], ItemReason::DownloadFailed);

                    return;
                }
            } else {
                $post->appendMedia($snapshot);
            }

            $posts[] = $post;
        }

        if ($posts === []) {
            $this->item->update(['status' => ItemStatus::Failed, 'reason' => ItemReason::PostCreationFailed]);

            return;
        }

        foreach ($posts as $post) {
            $post->update(['status' => PostStatus::Scheduled, 'scheduled_at' => now()]);

            PublishPost::dispatch($post);
        }

        $this->item->update(['status' => ItemStatus::Published, 'reason' => null, 'error' => null]);
    }

    public function failed(Throwable $exception): void
    {
        $this->item->update([
            'status' => ItemStatus::Failed,
            'error' => Str::limit($exception->getMessage(), 1000),
        ]);
    }

    /**
     * @param  array<int, Post>  $posts
     */
    private function discard(array $posts, ItemReason $reason): void
    {
        foreach ($posts as $post) {
            $post->forceDelete();
        }

        $this->item->update(['status' => ItemStatus::Failed, 'reason' => $reason]);
    }
}

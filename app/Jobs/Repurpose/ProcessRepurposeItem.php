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

class ProcessRepurposeItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

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
        if ($this->item->status->isTerminal()) {
            return;
        }

        $repurpose = $this->item->repurpose;
        $workspace = $repurpose->workspace;
        $user = $repurpose->user ?? $workspace->owner;

        if ($user === null) {
            $this->item->update(['status' => ItemStatus::Failed, 'reason' => ItemReason::PostCreationFailed]);

            return;
        }

        $this->item->posts()->each(fn (Post $post) => $post->forceDelete());

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
                    $this->discard([...$posts, $post], ItemReason::DownloadFailed);

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

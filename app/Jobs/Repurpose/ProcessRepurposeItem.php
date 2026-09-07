<?php

declare(strict_types=1);

namespace App\Jobs\Repurpose;

use App\Actions\Post\CreatePost;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\PublishMode;
use App\Exceptions\Repurpose\SourceDownloadException;
use App\Models\Post;
use App\Models\RepurposeItem;
use App\Services\Post\MediaAttacher;
use App\Services\Repurpose\CaptionAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessRepurposeItem implements ShouldBeUnique, ShouldQueue
{
    public int $uniqueFor = 3600;

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

    public function uniqueId(): string
    {
        return $this->item->id;
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

        if ($repurpose->publish_mode === PublishMode::Draft && $this->item->posts()->exists()) {
            $this->item->update(['status' => ItemStatus::Drafted]);

            return;
        }

        if ($this->item->posts()->where('status', '!=', PostStatus::Draft)->exists()) {
            $this->item->update(['status' => ItemStatus::Published]);

            return;
        }

        $this->item->posts()->each(fn (Post $post) => $post->forceDelete());

        $this->item->update(['status' => ItemStatus::Processing]);

        $posts = [];
        $snapshot = null;

        foreach ($repurpose->destinations as $destination) {
            $account = $workspace->socialAccounts()->find(data_get($destination, 'social_account_id'));

            if ($account === null || ! $account->is_active) {
                continue;
            }

            $post = CreatePost::execute($workspace, $user, [
                'content' => e($captions->adapt($workspace, $user, $this->caption, $account->platform)),
                'created_via' => CreatedVia::Repurpose,
                'platforms' => [$destination],
            ]);

            $post->update(['repurpose_item_id' => $this->item->id]);

            if ($snapshot === null) {
                $snapshot = data_get($media->attachFromUrls($post, [['url' => $this->downloadUrl]]), 'attached', []);

                if ($snapshot === []) {
                    $this->failDownload([...$posts, $post]);
                }
            } else {
                $post->appendMedia($snapshot);
            }

            $posts[] = $post;
        }

        if ($posts === []) {
            // Not PostCreationFailed: nothing was attempted. Every destination
            // resolved to an account that is gone or switched off.
            $this->item->update(['status' => ItemStatus::Failed, 'reason' => ItemReason::NoUsableDestinations]);

            return;
        }

        if ($repurpose->publish_mode === PublishMode::Draft) {
            $this->item->update(['status' => ItemStatus::Drafted, 'reason' => null, 'error' => null]);

            return;
        }

        DB::transaction(function () use ($posts): void {
            foreach ($posts as $post) {
                $post->update(['status' => PostStatus::Scheduled, 'scheduled_at' => now()]);
            }
        });

        $this->item->update(['status' => ItemStatus::Published, 'reason' => null, 'error' => null]);
    }

    /**
     * An attempt that died after creating some of its posts leaves them behind
     * as drafts. Every retry clears them on the way in, but the last one has no
     * successor — so without this they sit in the calendar with nothing
     * explaining where they came from.
     *
     * In draft mode the draft is the deliverable, not a leftover: the user can
     * already see and publish it, so a late failure keeps it and the item says
     * what actually happened.
     */
    public function failed(Throwable $exception): void
    {
        $drafts = $this->item->posts()->where('status', PostStatus::Draft)->get();

        if ($drafts->isNotEmpty() && $this->item->repurpose?->publish_mode === PublishMode::Draft) {
            $this->item->update(['status' => ItemStatus::Drafted, 'reason' => null, 'error' => null]);

            return;
        }

        $drafts->each(fn (Post $post) => $post->forceDelete());

        $this->item->update([
            'status' => ItemStatus::Failed,
            'reason' => $exception instanceof SourceDownloadException ? ItemReason::DownloadFailed : $this->item->reason,
            'error' => Str::limit($exception->getMessage(), 1000),
        ]);
    }

    /**
     * Throws so the job's own retries get a chance at it: a source video that is
     * not downloadable right now usually is minutes later. {@see self::failed()}
     * turns the exhausted attempt into the stored reason.
     *
     * @param  array<int, Post>  $posts
     */
    private function failDownload(array $posts): never
    {
        foreach ($posts as $post) {
            $post->forceDelete();
        }

        throw new SourceDownloadException("Could not download the source video for repurpose item {$this->item->id}.");
    }
}

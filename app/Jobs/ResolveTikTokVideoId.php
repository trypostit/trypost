<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Post\AssignTikTokVideoId;
use App\Models\Post;
use App\Services\Social\TikTokPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Asks TikTok again for the public video id of a post it published before
 * moderation finished, so its link stops pointing at the profile.
 */
class ResolveTikTokVideoId implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * TikTok moderation usually ends within a minute of the publish, so the
     * publish job asks once after that; the sweep covers slower reviews.
     */
    public const int FIRST_CHECK_AFTER_SECONDS = 60;

    public bool $deleteWhenMissingModels = true;

    /** Must exceed HasSocialHttpClient's 120s HTTP timeout so a slow request cannot kill the worker. */
    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(public Post $post)
    {
        $this->onQueue($post->platform->queue());
    }

    public function uniqueId(): string
    {
        return $this->post->id;
    }

    public function handle(TikTokPublisher $publisher, AssignTikTokVideoId $assignVideoId): void
    {
        $this->post->refresh();

        if (! $this->post->awaitsTikTokVideoId() || ! $this->post->socialAccount()->connected()->exists()) {
            return;
        }

        $videoId = $publisher->publicVideoId($this->post);
        $this->post->writePublication(['last_reconciled_at' => now()]);

        if (blank($videoId)) {
            return;
        }

        try {
            $assignVideoId->handle($this->post, $videoId);
        } catch (LogicException) {
            if (Cache::add("tiktok-video-id:held-by-another-post:{$this->post->id}", true, now()->addDay())) {
                Log::warning('TikTok reported a video another TryPost post holds; not assigned.', [
                    'post_id' => $this->post->id,
                    'video_id' => $videoId,
                ]);
            }
        }
    }
}

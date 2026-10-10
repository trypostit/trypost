<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Post\AssignTikTokVideoId;
use App\Models\Post;
use App\Services\Social\TikTokPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Asks TikTok again for the public video id of a post it published before
 * moderation finished, so its link stops pointing at the profile.
 */
class ResolveTikTokVideoId implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

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

        if (! $this->post->awaitsTikTokVideoId()) {
            return;
        }

        $videoId = $publisher->publicVideoId($this->post);
        $this->post->writePublication(['last_reconciled_at' => now()]);

        if (filled($videoId)) {
            $assignVideoId->handle($this->post, $videoId);
        }
    }
}

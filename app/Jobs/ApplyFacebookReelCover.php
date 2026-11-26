<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Services\Social\FacebookPublisher;
use App\Support\Social\CoverRetry;
use App\Support\Social\FacebookCover;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends the cover image of a Reel Facebook has processed
 * (`SetFacebookReelCover` waits for that) to `/{video-id}/thumbnails`. A
 * failure a later try may fix is tried again after 1, 2, 5 and 10 minutes
 * (`CoverRetry`); a cover Facebook refuses, or the last try failing, leaves a
 * warning on the post, never a failure.
 */
class ApplyFacebookReelCover implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = CoverRetry::TRIES;

    /**
     * The waits after a try that throws, like the ones the job releases itself.
     *
     * @var list<int>
     */
    public array $backoff = CoverRetry::BACKOFF_SECONDS;

    public bool $deleteWhenMissingModels = true;

    /**
     * A try downloads the cover and uploads it, each of up to 120s
     * (HasSocialHttpClient), and the HTTP client tries the upload up to three
     * times on a 429. It stays under the Redis queue's 960s retry_after.
     */
    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public Post $post)
    {
        $this->onQueue($post->platform->queue());
    }

    public function uniqueId(): string
    {
        return $this->post->id;
    }

    public function handle(FacebookPublisher $publisher): void
    {
        $this->post->refresh();

        if (! FacebookCover::awaitsReelCover($this->post)) {
            return;
        }

        if (! FacebookCover::channelTakesReelCover($this->post)) {
            FacebookCover::recordRefusal($this->post);

            return;
        }

        try {
            $publisher->setReelCover($this->post);
        } catch (PlatformUnavailableException $exception) {
            $this->release(CoverRetry::delayAfter($this->attempts(), $exception->retryDelaySeconds));
        } catch (TokenExpiredException) {
            FacebookCover::recordRefusal($this->post);
        } catch (FacebookPublishException $exception) {
            $this->settle($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $post = Post::find($this->post->id);

        if (filled($post) && FacebookCover::awaitsReelCover($post)) {
            FacebookCover::recordRefusal($post);
        }
    }

    /**
     * A cover that could not be downloaded is tried again; one that cannot be
     * made a JPEG, or that Facebook refuses, never will be. A Reel Graph
     * cannot load (code 100, subcode 33) gets the warning too: Graph answers
     * the same for a lost permission as for a deleted video.
     */
    private function settle(FacebookPublishException $exception): void
    {
        if ($exception->category === ErrorCategory::ServerError) {
            $this->release(CoverRetry::delayAfter($this->attempts()));

            return;
        }

        FacebookCover::recordRefusal($this->post);
    }
}

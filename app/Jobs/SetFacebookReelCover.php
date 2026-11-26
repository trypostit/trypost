<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Facebook\CoverFailure;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Services\Social\FacebookPublisher;
use App\Support\Social\FacebookCover;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Waits until Facebook has processed a Reel TryPost published, then hands its
 * cover to `ApplyFacebookReelCover`: a Reel takes a cover only once it is up
 * (`/{video-id}/thumbnails`). The Reel is already live: a Reel Facebook could
 * not process, or a channel without the cover's permission, leaves a warning
 * on the post, never a failure.
 */
class SetFacebookReelCover implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Facebook usually has the Reel processed within a minute of the publish; until then the job checks again every minute. */
    public const int CHECK_EVERY_SECONDS = 60;

    /** Thirty minutes of checks. */
    public int $tries = 30;

    public int $backoff = self::CHECK_EVERY_SECONDS;

    public bool $deleteWhenMissingModels = true;

    /**
     * A check is one status read of up to 120s (HasSocialHttpClient), which the
     * HTTP client tries up to three times on a 429. It stays under the Redis
     * queue's 960s retry_after.
     */
    public int $timeout = 400;

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

        if (! FacebookCover::isAwaitedBy($this->post)) {
            return;
        }

        $channelFailure = FacebookCover::channelFailure($this->post);

        if (filled($channelFailure)) {
            FacebookCover::recordWarning($this->post, FacebookCover::failure($channelFailure));

            return;
        }

        try {
            if ($publisher->reelIsProcessing($this->post)) {
                $this->release(self::CHECK_EVERY_SECONDS);

                return;
            }
        } catch (PlatformUnavailableException) {
            $this->release(self::CHECK_EVERY_SECONDS);

            return;
        } catch (TokenExpiredException) {
            FacebookCover::recordWarning($this->post, FacebookCover::failure(CoverFailure::TokenExpired));

            return;
        } catch (FacebookPublishException $exception) {
            FacebookCover::recordWarning($this->post, FacebookCover::failure(self::readFailure($exception), $exception));

            return;
        }

        ApplyFacebookReelCover::dispatch($this->post);
    }

    /**
     * A Reel Facebook was still processing after thirty minutes of checks keeps
     * Facebook's own cover.
     */
    public function failed(?Throwable $exception): void
    {
        $post = Post::find($this->post->id);

        if (filled($post) && FacebookCover::isAwaitedBy($post)) {
            FacebookCover::recordWarning($post, FacebookCover::failure(CoverFailure::StillProcessing));
        }
    }

    /** A Reel Facebook could not process (`reelIsProcessing()` throws it as MediaFormat), else a status read it refused. */
    private static function readFailure(FacebookPublishException $exception): CoverFailure
    {
        if ($exception->category === ErrorCategory::MediaFormat) {
            return CoverFailure::ProcessingFailed;
        }

        return CoverFailure::Refused;
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\YouTube\ThumbnailWarning;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\YouTubePublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\YouTubePublisher;
use App\Support\Social\CoverRetry;
use App\Support\Social\YouTubeCover;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sets the cover image of a video YouTube has processed (`SetYouTubeCover`
 * waits for that) as its thumbnail. A failure a later try may fix is tried
 * again after 1, 2, 5 and 10 minutes (`CoverRetry`); a cover YouTube refuses,
 * or the last try failing, leaves a warning on the post, never a failure.
 */
class ApplyYouTubeCover implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = CoverRetry::TRIES;

    /**
     * The waits after a try that throws (a network error the Google client
     * does not answer), like the ones the job releases itself.
     *
     * @var list<int>
     */
    public array $backoff = CoverRetry::BACKOFF_SECONDS;

    public bool $deleteWhenMissingModels = true;

    /**
     * A try makes up to three requests of up to 120s each (HasSocialHttpClient
     * and the cover download): the token refresh, the cover download and the
     * upload. It stays under the Redis queue's 960s retry_after.
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

    public function handle(YouTubePublisher $publisher): void
    {
        $this->post->refresh();

        if (! YouTubeCover::isAwaitedBy($this->post)) {
            return;
        }

        $account = $this->post->socialAccount()->connected()->first();

        if (blank($account)) {
            YouTubeCover::recordWarning($this->post, ThumbnailWarning::Failed);

            return;
        }

        try {
            if ($account->needsProactiveTokenRefresh()) {
                app(ConnectionVerifier::class)->refreshToken($account);
            }

            $publisher->setCover($this->post);
        } catch (TokenExpiredException) {
            YouTubeCover::recordWarning($this->post, ThumbnailWarning::Failed);
        } catch (GoogleServiceException $exception) {
            $this->settle($exception);
        } catch (YouTubePublishException $exception) {
            $this->settleCoverFile($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $post = Post::find($this->post->id);

        if (filled($post) && YouTubeCover::isAwaitedBy($post)) {
            YouTubeCover::recordWarning($post, ThumbnailWarning::Failed);
        }
    }

    /**
     * A server error is tried again, and a video removed meanwhile (404
     * `videoNotFound`) leaves nothing to warn about; any other refusal stays
     * on the post.
     *
     * @see https://developers.google.com/youtube/v3/docs/thumbnails/set#errors
     */
    private function settle(GoogleServiceException $exception): void
    {
        $status = $exception->getCode();

        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            $this->release(CoverRetry::delayAfter($this->attempts()));

            return;
        }

        if ($status === Response::HTTP_NOT_FOUND) {
            return;
        }

        YouTubeCover::recordWarning($this->post, YouTubeCover::warningFor($status));
    }

    /**
     * A cover that cannot be made a JPEG never will be; a download that
     * failed is tried again.
     */
    private function settleCoverFile(YouTubePublishException $exception): void
    {
        if ($exception->category === ErrorCategory::MediaFormat) {
            YouTubeCover::recordWarning($this->post, ThumbnailWarning::InvalidImage);

            return;
        }

        $this->release(CoverRetry::delayAfter($this->attempts()));
    }
}

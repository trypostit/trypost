<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\YouTube\ProcessingStatus;
use App\Enums\YouTube\ThumbnailWarning;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\YouTubePublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\YouTubePublisher;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sets the cover image of a video TryPost published on YouTube. YouTube
 * applies a Short's thumbnail only once the video is processed, so the job
 * waits for that, a minute at a time. The video is already live: a cover
 * YouTube refuses leaves a warning on the post, never a failure.
 */
class SetYouTubeCover implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** YouTube usually processes a Short within a minute or two of the upload. */
    public const int CHECK_EVERY_SECONDS = 60;

    /** Thirty minutes of processing checks. */
    public int $tries = 30;

    public int $backoff = self::CHECK_EVERY_SECONDS;

    public bool $deleteWhenMissingModels = true;

    /** Must exceed HasSocialHttpClient's 120s HTTP timeout so a slow request cannot kill the worker. */
    public int $timeout = 180;

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

        if (! $this->post->awaitsYouTubeCover()) {
            return;
        }

        $account = $this->post->socialAccount()->connected()->first();

        if (blank($account)) {
            $this->post->recordYouTubeThumbnailWarning(ThumbnailWarning::Failed);

            return;
        }

        try {
            if ($account->needsProactiveTokenRefresh()) {
                app(ConnectionVerifier::class)->refreshToken($account);
            }

            $this->apply($publisher);
        } catch (TokenExpiredException) {
            $this->post->recordYouTubeThumbnailWarning(ThumbnailWarning::Failed);
        } catch (GoogleServiceException $exception) {
            $this->settle($exception);
        } catch (YouTubePublishException $exception) {
            $this->settleCoverFile($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->post->refresh();

        if ($this->post->awaitsYouTubeCover()) {
            $this->post->recordYouTubeThumbnailWarning(ThumbnailWarning::Failed);
        }
    }

    /**
     * A video YouTube no longer lists was removed: there is nothing to warn
     * about. One it could not process never takes a thumbnail.
     *
     * @throws GoogleServiceException
     */
    private function apply(YouTubePublisher $publisher): void
    {
        $status = $publisher->processingStatus($this->post);

        if ($status === ProcessingStatus::Processing) {
            $this->release(self::CHECK_EVERY_SECONDS);

            return;
        }

        if (blank($status)) {
            return;
        }

        if ($status !== ProcessingStatus::Succeeded) {
            $this->post->recordYouTubeThumbnailWarning(ThumbnailWarning::Failed);

            return;
        }

        $publisher->setCover($this->post);
    }

    /**
     * A server error is tried again, and a video removed meanwhile (404
     * `videoNotFound`) leaves nothing to warn about, like a video YouTube no
     * longer lists; any other refusal stays on the post.
     *
     * @see https://developers.google.com/youtube/v3/docs/thumbnails/set#errors
     */
    private function settle(GoogleServiceException $exception): void
    {
        $status = $exception->getCode();

        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            $this->release(self::CHECK_EVERY_SECONDS);

            return;
        }

        if ($status === Response::HTTP_NOT_FOUND) {
            return;
        }

        $this->post->recordYouTubeThumbnailWarning(self::warningFor($status));
    }

    /**
     * A cover that cannot be made a JPEG never will be; a download that
     * failed is tried again.
     *
     * @throws YouTubePublishException
     */
    private function settleCoverFile(YouTubePublishException $exception): void
    {
        if ($exception->category !== ErrorCategory::MediaFormat) {
            throw $exception;
        }

        $this->post->recordYouTubeThumbnailWarning(ThumbnailWarning::InvalidImage);
    }

    private static function warningFor(int $status): ThumbnailWarning
    {
        if ($status === Response::HTTP_FORBIDDEN) {
            return ThumbnailWarning::Forbidden;
        }

        if ($status === Response::HTTP_TOO_MANY_REQUESTS) {
            return ThumbnailWarning::DailyLimit;
        }

        if ($status === Response::HTTP_BAD_REQUEST) {
            return ThumbnailWarning::InvalidImage;
        }

        return ThumbnailWarning::Failed;
    }
}

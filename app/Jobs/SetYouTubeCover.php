<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\YouTube\ProcessingStatus;
use App\Enums\YouTube\ThumbnailWarning;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\YouTubePublisher;
use App\Support\Social\YouTubeCover;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Waits until YouTube has processed a video TryPost published, then hands its
 * cover to `ApplyYouTubeCover`: YouTube applies a Short's thumbnail only once
 * the video is processed. The video is already live: a video YouTube could not
 * process leaves a warning on the post, never a failure.
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

    /**
     * A check makes up to two requests of up to 120s each (HasSocialHttpClient):
     * the token refresh and the status read. It stays under the Redis queue's
     * 960s retry_after.
     */
    public int $timeout = 300;

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

            $status = $publisher->processingStatus($this->post);
        } catch (TokenExpiredException) {
            YouTubeCover::recordWarning($this->post, ThumbnailWarning::Failed);

            return;
        } catch (GoogleServiceException $exception) {
            $this->settle($exception);

            return;
        }

        $this->follow($status);
    }

    public function failed(?Throwable $exception): void
    {
        $post = Post::find($this->post->id);

        if (filled($post) && YouTubeCover::isAwaitedBy($post)) {
            YouTubeCover::recordWarning($post, ThumbnailWarning::Failed);
        }
    }

    /**
     * A video YouTube no longer lists was removed: there is nothing to warn
     * about. One it could not process never takes a thumbnail.
     */
    private function follow(?ProcessingStatus $status): void
    {
        if ($status === ProcessingStatus::Processing) {
            $this->release(self::CHECK_EVERY_SECONDS);

            return;
        }

        if (blank($status)) {
            return;
        }

        if ($status !== ProcessingStatus::Succeeded) {
            YouTubeCover::recordWarning($this->post, ThumbnailWarning::Failed);

            return;
        }

        ApplyYouTubeCover::dispatch($this->post);
    }

    /**
     * A server error checks again, and a video removed meanwhile (404) leaves
     * nothing to warn about; any other refusal stays on the post.
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

        YouTubeCover::recordWarning($this->post, YouTubeCover::warningFor($status));
    }
}

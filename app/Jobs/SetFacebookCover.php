<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SocialAccount\GatedFeature;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\Meta\GraphError;
use App\Support\ChannelPermissions;
use App\Support\Social\FacebookCover;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sets the cover image of a Reel TryPost published on a Facebook Page, which
 * Facebook takes only once the Reel is up (`/{video-id}/thumbnails`). The Reel
 * is already live: a cover Facebook refuses, or a channel without the cover's
 * permission, leaves a warning on the post, never a failure.
 */
class SetFacebookCover implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Facebook usually has the Reel processed within a minute of the publish; until then the job checks again every minute. */
    public const int CHECK_EVERY_SECONDS = 60;

    /** What a later try may fix: Facebook or the network was down, or the cover could not be fetched. */
    private const array RETRYABLE_CATEGORIES = [
        ErrorCategory::ServerError,
        ErrorCategory::RateLimit,
        ErrorCategory::PlatformUnavailable,
        ErrorCategory::Timeout,
    ];

    /** Thirty minutes of tries. */
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

    public function handle(FacebookPublisher $publisher): void
    {
        $this->post->refresh();

        if (! FacebookCover::isAwaitedBy($this->post)) {
            return;
        }

        $account = $this->post->socialAccount()->connected()->first();

        if (blank($account)) {
            FacebookCover::recordRefusal($this->post);

            return;
        }

        if (! ChannelPermissions::permits($account, GatedFeature::VideoCover)) {
            FacebookCover::recordRefusal($this->post);

            return;
        }

        try {
            if ($publisher->reelIsProcessing($this->post)) {
                $this->release(self::CHECK_EVERY_SECONDS);

                return;
            }

            $publisher->setReelCover($this->post);
        } catch (PlatformUnavailableException) {
            $this->release(self::CHECK_EVERY_SECONDS);
        } catch (TokenExpiredException) {
            FacebookCover::recordRefusal($this->post);
        } catch (FacebookPublishException $exception) {
            $this->settle($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $post = Post::find($this->post->id);

        if (filled($post) && FacebookCover::isAwaitedBy($post)) {
            FacebookCover::recordRefusal($post);
        }
    }

    /**
     * Tries again what a later try may fix and records the warning for any
     * other refusal. A Reel Graph cannot load (code 100, subcode 33) gets the
     * warning too: Graph answers the same for a lost permission as for a
     * deleted video.
     */
    private function settle(FacebookPublishException $exception): void
    {
        if (self::isRetryable($exception)) {
            $this->release(self::CHECK_EVERY_SECONDS);

            return;
        }

        FacebookCover::recordRefusal($this->post);
    }

    /** A failure a later try may fix, by its category or by Meta's transient error codes (`GraphError`). */
    private static function isRetryable(FacebookPublishException $exception): bool
    {
        if (collect(self::RETRYABLE_CATEGORIES)->containsStrict($exception->category)) {
            return true;
        }

        if (blank($exception->rawResponse)) {
            return false;
        }

        $body = json_decode($exception->rawResponse, true);

        if (! is_array($body)) {
            return false;
        }

        return GraphError::isTransient($body);
    }
}

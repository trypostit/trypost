<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\Facebook\CoverFailure;
use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\Post;
use App\Support\ChannelPermissions;
use Illuminate\Support\Arr;

/**
 * The cover image of a Facebook video: a Reel takes it once it is up
 * (`SetFacebookReelCover` waits for that, `ApplyFacebookReelCover` sends it),
 * a feed video with its upload. A cover Facebook did not take leaves a
 * warning in posts.error_context; the post stays published.
 */
final class FacebookCover
{
    public const string WARNING = 'facebook_cover_warning';

    public const string FAILURE = 'facebook_cover_failure';

    /**
     * A Facebook Reel TryPost published with a cover image, waiting for its
     * cover. An install that turned the Reel cover off (its scopes left out)
     * sets none and warns about none, even for a cover saved before.
     */
    public static function isAwaitedBy(Post $post): bool
    {
        return $post->platform === Platform::Facebook
            && $post->origin === Origin::TryPost
            && $post->publish_status === PublishStatus::Published
            && filled($post->platform_post_id)
            && $post->content_type === ContentType::FacebookReel
            && filled($post->mediaItems->first()?->cover())
            && ChannelPermissions::enabled(Platform::Facebook, GatedFeature::FacebookReelCover);
    }

    /** Why the post's channel cannot take the Reel's cover now, if it cannot: it is no longer connected, or it lacks the cover's permission. */
    public static function channelFailure(Post $post): ?CoverFailure
    {
        $account = $post->socialAccount()->connected()->first();

        if (blank($account)) {
            return CoverFailure::NotConnected;
        }

        if (! ChannelPermissions::permits($account, GatedFeature::FacebookReelCover)) {
            return CoverFailure::NoPermission;
        }

        return null;
    }

    /**
     * Whether Facebook kept its own cover instead of the image picked for the video.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function hasWarning(?array $context): bool
    {
        return data_get($context, self::WARNING) === true;
    }

    /**
     * The warning sits next to the publication result, which publishing again
     * clears, with why the cover did not go on (`failure()`).
     *
     * @param  array<string, mixed>  $failure
     */
    public static function recordWarning(Post $post, array $failure): void
    {
        $post->writePublication(['error_context' => [
            ...($post->error_context ?? []),
            self::WARNING => true,
            self::FAILURE => $failure,
        ]]);
    }

    /**
     * The failure a try that is tried again ran into, kept without a warning so
     * the warning left when the tries run out says what kept failing.
     *
     * @param  array<string, mixed>  $failure
     */
    public static function recordLastError(Post $post, array $failure): void
    {
        $post->writePublication(['error_context' => [...($post->error_context ?? []), self::FAILURE => $failure]]);
    }

    /** A cover that went on after a failed try keeps no failure. */
    public static function forgetLastError(Post $post): void
    {
        if (blank(data_get($post->error_context, self::FAILURE))) {
            return;
        }

        $post->writePublication(['error_context' => Arr::except($post->error_context, self::FAILURE) ?: null]);
    }

    /**
     * The warning for tries or checks that ran out, with what kept failing as
     * the last one recorded it.
     */
    public static function recordGaveUp(Post $post, CoverFailure $reason): void
    {
        self::recordWarning($post, [
            ...data_get($post->error_context, self::FAILURE, []),
            'reason' => $reason->value,
        ]);
    }

    /**
     * Why the cover did not go on, and Facebook's answer when it gave one,
     * the way a failed post keeps it (`category`, `platform_error_code`, the
     * raw response without its token).
     *
     * @return array<string, string>
     */
    public static function failure(CoverFailure $reason, ?SocialPublishException $exception = null): array
    {
        if (blank($exception)) {
            return ['reason' => $reason->value];
        }

        return Arr::whereNotNull([
            'reason' => $reason->value,
            'category' => $exception->category->value,
            'platform_error_code' => $exception->platformErrorCode,
            'raw_response' => data_get($exception->context(), 'raw_response'),
        ]);
    }

    /**
     * A refusal a later try may fix (a rate limit, a server error, a cover
     * that did not download), as Facebook or the download answered it.
     *
     * @return array<string, string>
     */
    public static function retried(SocialPublishException $exception): array
    {
        return Arr::whereNotNull([
            'category' => $exception->category->value,
            'platform_error_code' => $exception->platformErrorCode,
            'raw_response' => data_get($exception->context(), 'raw_response'),
        ]);
    }

    /**
     * A failure a later try may fix: Facebook down or limiting, or unreachable.
     *
     * @return array<string, int|string>
     */
    public static function unavailable(PlatformUnavailableException $exception): array
    {
        return Arr::whereNotNull([
            'http_status' => $exception->httpStatus,
            'detail' => $exception->getMessage(),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\YouTube\ThumbnailWarning;
use App\Models\Post;
use Symfony\Component\HttpFoundation\Response;

/**
 * The cover image of a YouTube video, which YouTube only takes once the video
 * is processed (`SetYouTubeCover` waits for that, `ApplyYouTubeCover` sends
 * it). Why it did not take it is kept in
 * posts.error_context; the post stays published.
 */
final class YouTubeCover
{
    public const string WARNING = 'youtube_thumbnail_warning';

    /** A YouTube video TryPost published with an image picked as its cover, waiting for its cover. */
    public static function isAwaitedBy(Post $post): bool
    {
        return $post->platform === Platform::YouTube
            && $post->origin === Origin::TryPost
            && $post->publish_status === PublishStatus::Published
            && filled($post->platform_post_id)
            && filled($post->mediaItems->first()?->cover());
    }

    /**
     * Why YouTube did not set the cover image as the video's thumbnail, if it did not.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function warning(?array $context): ?ThumbnailWarning
    {
        return ThumbnailWarning::tryFrom((string) data_get($context, self::WARNING));
    }

    /**
     * The warning for a refusal YouTube answered with this status.
     *
     * @see https://developers.google.com/youtube/v3/docs/thumbnails/set#errors
     */
    public static function warningFor(int $status): ThumbnailWarning
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

    /** The warning sits next to the publication result, which publishing again clears. */
    public static function recordWarning(Post $post, ThumbnailWarning $warning): void
    {
        $post->writePublication(['error_context' => [...($post->error_context ?? []), self::WARNING => $warning->value]]);
    }
}

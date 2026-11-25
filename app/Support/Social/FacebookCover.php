<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;

/**
 * The cover image of a Facebook video: a Reel takes it once it is up
 * (`SetFacebookReelCover`), a feed video with its upload. A cover Facebook did
 * not take leaves a warning in posts.error_context; the post stays published.
 */
final class FacebookCover
{
    public const string WARNING = 'facebook_cover_warning';

    /** A Facebook Reel TryPost published with a cover image, waiting for `SetFacebookReelCover`. */
    public static function awaitsReelCover(Post $post): bool
    {
        return $post->platform === Platform::Facebook
            && $post->origin === Origin::TryPost
            && $post->publish_status === PublishStatus::Published
            && filled($post->platform_post_id)
            && $post->content_type === ContentType::FacebookReel
            && filled($post->mediaItems->first()?->cover());
    }

    /**
     * Whether Facebook kept its own cover instead of the image picked for the video.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function wasRefused(?array $context): bool
    {
        return data_get($context, self::WARNING) === true;
    }

    /** The warning sits next to the publication result, which publishing again clears. */
    public static function recordRefusal(Post $post): void
    {
        $post->writePublication(['error_context' => [...($post->error_context ?? []), self::WARNING => true]]);
    }
}

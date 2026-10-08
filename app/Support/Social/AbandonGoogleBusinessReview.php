<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Actions\Post\FinalizePostPublication;
use App\Enums\Post\PublishStatus;
use App\Events\PostStatusUpdated;
use App\Models\Post;

/**
 * Stops waiting on a Google Business review we can no longer settle remotely
 * (disconnected account, recover sweep). Rejects the publication, prunes the
 * JPEG, and lets FinalizePostPublication close the post.
 */
class AbandonGoogleBusinessReview
{
    /**
     * @param  array<string, mixed>  $errorContext
     */
    public static function execute(Post $post, string $errorMessage, array $errorContext = []): void
    {
        if ($post->publish_status === PublishStatus::PendingReview) {
            $post->markPublicationRejected(
                (string) $post->platform_post_id,
                $post->platform_url,
                $errorMessage,
                $errorContext,
            );
        }

        app(GoogleBusinessDerivativeCleaner::class)->cleanup($post);
        app(FinalizePostPublication::class)->handle($post);
        PostStatusUpdated::dispatch($post->fresh());
    }
}

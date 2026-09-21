<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Actions\Post\FinalizePostPublication;
use App\Enums\PostPlatform\Status;
use App\Events\PostPlatformStatusUpdated;
use App\Models\PostPlatform;

/**
 * Stops waiting on a Google Business review we can no longer settle remotely
 * (disconnected account, switched-off target, recover sweep). Rejects the
 * row, prunes the JPEG, and lets FinalizePostPublication close the parent.
 */
class AbandonGoogleBusinessReview
{
    /**
     * @param  array<string, mixed>  $errorContext
     */
    public static function execute(PostPlatform $postPlatform, string $errorMessage, array $errorContext = []): void
    {
        if ($postPlatform->status === Status::PendingReview) {
            $postPlatform->markAsRejected(
                (string) $postPlatform->platform_post_id,
                $postPlatform->platform_url,
                $errorMessage,
                $errorContext,
            );
        }

        app(GoogleBusinessDerivativeCleaner::class)->cleanup($postPlatform->id);
        app(FinalizePostPublication::class)->handle($postPlatform->post);
        PostPlatformStatusUpdated::dispatch($postPlatform->fresh());
    }
}

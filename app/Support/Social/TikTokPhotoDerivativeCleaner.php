<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TikTokPhotoDerivativeCleaner
{
    public const string DIRECTORY = 'social-tiktok-photos';

    /**
     * @param  array<string, mixed>|null  $context
     */
    public function cleanup(?array $context, ?string $postId = null): void
    {
        $this->cleanupPaths(PublishCheckpoint::tiktokDerivativePaths($context), $postId);
    }

    /**
     * Keep hosted photos while a publish_id can still be resumed.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function cleanupUnlessPublishInFlight(?array $context, ?string $postId = null): void
    {
        if (PublishCheckpoint::tiktokPublishId($context) !== null) {
            return;
        }

        $this->cleanup($context, $postId);
    }

    /**
     * @param  array<array-key, mixed>  $paths
     */
    public function cleanupPaths(array $paths, ?string $postId = null): void
    {
        $derivativePaths = array_values(array_filter(
            $paths,
            $this->isManagedDerivativePath(...),
        ));

        if ($derivativePaths === []) {
            return;
        }

        try {
            Storage::delete($derivativePaths);
        } catch (Throwable $e) {
            Log::warning('Failed to prune TikTok photo derivatives', [
                'post_id' => $postId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isManagedDerivativePath(mixed $path): bool
    {
        return is_string($path)
            && dirname($path) === self::DIRECTORY
            && Str::isUuid(pathinfo($path, PATHINFO_FILENAME));
    }
}

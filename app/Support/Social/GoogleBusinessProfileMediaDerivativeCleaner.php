<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GoogleBusinessProfileMediaDerivativeCleaner
{
    public const string DIRECTORY = 'social-google-business-profile-media';

    /** @param array<string, mixed>|null $context */
    public function cleanup(?array $context, ?string $postPlatformId = null): void
    {
        $this->cleanupPath(PublishCheckpoint::googleBusinessProfileDerivativePath($context), $postPlatformId);
    }

    public function cleanupPath(?string $path, ?string $postPlatformId = null): void
    {
        if (! $this->isManagedDerivativePath($path)) {
            return;
        }

        try {
            Storage::delete($path);
        } catch (Throwable $e) {
            Log::warning('Failed to prune Google Business Profile media derivative', [
                'post_platform_id' => $postPlatformId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isManagedDerivativePath(mixed $path): bool
    {
        return is_string($path)
            && dirname($path) === self::DIRECTORY
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)
            && Str::isUuid(pathinfo($path, PATHINFO_FILENAME));
    }
}

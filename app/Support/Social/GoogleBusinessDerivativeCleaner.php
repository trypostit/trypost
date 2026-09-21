<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GoogleBusinessDerivativeCleaner
{
    public const string DIRECTORY = 'google-business-derivatives';

    public static function pathFor(string $postPlatformId): string
    {
        return self::DIRECTORY.'/'.$postPlatformId.'.jpg';
    }

    public function cleanup(string $postPlatformId): void
    {
        $path = self::pathFor($postPlatformId);

        if (! Storage::exists($path)) {
            return;
        }

        try {
            Storage::delete($path);
        } catch (Throwable $e) {
            Log::warning('Failed to prune Google Business Profile image derivative', [
                'post_platform_id' => $postPlatformId,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

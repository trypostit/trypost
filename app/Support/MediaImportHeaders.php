<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The request headers of one import (they can carry the user's access
 * token), kept encrypted in the cache for a few minutes instead of in the
 * queued job, and read once by the job.
 */
final class MediaImportHeaders
{
    private const int TTL_SECONDS = 600;

    /**
     * @param  array<string, string>  $headers
     */
    public static function put(string $importId, array $headers): void
    {
        Cache::put(self::key($importId), Crypt::encryptString((string) json_encode($headers)), self::TTL_SECONDS);
    }

    /**
     * The headers, removed from the cache; empty when they expired.
     *
     * @return array<string, string>
     */
    public static function pull(string $importId): array
    {
        $encrypted = Cache::pull(self::key($importId));

        if (! is_string($encrypted)) {
            return [];
        }

        try {
            $headers = json_decode(Crypt::decryptString($encrypted), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($headers) ? $headers : [];
    }

    public static function forget(string $importId): void
    {
        Cache::forget(self::key($importId));
    }

    public static function key(string $importId): string
    {
        return "media-import-headers:{$importId}";
    }
}

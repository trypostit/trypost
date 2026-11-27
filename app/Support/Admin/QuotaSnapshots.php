<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Enums\Admin\QuotaSource;
use Illuminate\Support\Facades\Cache;

/**
 * The latest quota reading of each source, as the provider returned it, kept
 * in the cache so a page view never calls the provider. Only the latest one:
 * no history is stored. Raw arrays only (`cache.serializable_classes` is off).
 */
final class QuotaSnapshots
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function put(QuotaSource $source, array $data): void
    {
        Cache::forever(self::key($source), [
            'taken_at' => now('UTC')->toIso8601String(),
            'data' => $data,
        ]);
    }

    /**
     * @return array{taken_at: string, data: array<string, mixed>}|null
     */
    public static function latest(QuotaSource $source): ?array
    {
        $snapshot = Cache::get(self::key($source));

        if (! is_array($snapshot)) {
            return null;
        }

        return $snapshot;
    }

    private static function key(QuotaSource $source): string
    {
        return "admin:quota-snapshot:{$source->value}";
    }
}

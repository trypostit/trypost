<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tracks an Insights PDF while the queue renders it. The record lives in the cache
 * for as long as the file is kept, so a link stops working once the file is pruned.
 */
class InsightsPdfExports
{
    public const string PENDING = 'pending';

    public const string READY = 'ready';

    public const string FAILED = 'failed';

    /** Every file sits under this folder of the default disk. */
    public const string DIRECTORY = 'insights-pdf';

    /** How long a generated PDF stays downloadable. */
    public const int RETENTION_HOURS = 24;

    public static function start(User $user, string $filename): string
    {
        $id = (string) Str::uuid7();

        Cache::put(self::key($id), [
            'user_id' => $user->id,
            'status' => self::PENDING,
            'filename' => $filename,
            'path' => null,
        ], now()->addHours(self::RETENTION_HOURS));

        return $id;
    }

    /**
     * @return array{user_id: string, status: string, filename: string, path: string|null}|null
     */
    public static function find(string $id): ?array
    {
        return Cache::get(self::key($id));
    }

    /**
     * The export, only when the user asked for it.
     *
     * @return array{user_id: string, status: string, filename: string, path: string|null}|null
     */
    public static function findFor(User $user, string $id): ?array
    {
        $export = self::find($id);

        return data_get($export, 'user_id') === $user->id ? $export : null;
    }

    public static function path(string $id): string
    {
        return self::DIRECTORY."/{$id}.pdf";
    }

    public static function markReady(string $id): void
    {
        self::update($id, ['status' => self::READY, 'path' => self::path($id)]);
    }

    public static function markFailed(string $id): void
    {
        self::update($id, ['status' => self::FAILED]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function update(string $id, array $changes): void
    {
        $export = self::find($id);

        if ($export === null) {
            return;
        }

        Cache::put(self::key($id), [...$export, ...$changes], now()->addHours(self::RETENTION_HOURS));
    }

    private static function key(string $id): string
    {
        return "insights-pdf:{$id}";
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Cache;

/**
 * The state of one queued import, kept in the cache for an hour and bound
 * to the user and workspace that started it.
 */
final class MediaImportStatus
{
    public const string PENDING = 'pending';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    private const int TTL_SECONDS = 3600;

    public static function pending(string $importId, string $userId, string $workspaceId, ?string $replaces = null): void
    {
        self::put($importId, [
            'status' => self::PENDING,
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'media_id' => null,
            'reason' => null,
            'replaces' => $replaces,
        ]);
    }

    public static function complete(string $importId, Media $media): void
    {
        self::transition($importId, ['status' => self::DONE, 'media_id' => $media->id]);
    }

    public static function fail(string $importId, string $reason): void
    {
        self::transition($importId, ['status' => self::FAILED, 'reason' => $reason]);
    }

    /**
     * The entry, or null when it expired or belongs to another user or
     * workspace.
     *
     * @return array{status: string, user_id: string, workspace_id: string, media_id: ?string, reason: ?string, replaces: ?string}|null
     */
    public static function find(string $importId, string $userId, string $workspaceId): ?array
    {
        $entry = Cache::get(self::key($importId));

        if (! is_array($entry)
            || data_get($entry, 'user_id') !== $userId
            || data_get($entry, 'workspace_id') !== $workspaceId) {
            return null;
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function transition(string $importId, array $changes): void
    {
        $entry = Cache::get(self::key($importId));

        if (! is_array($entry)) {
            return;
        }

        self::put($importId, [...$entry, ...$changes]);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function put(string $importId, array $entry): void
    {
        Cache::put(self::key($importId), $entry, self::TTL_SECONDS);
    }

    private static function key(string $importId): string
    {
        return "media-import:{$importId}";
    }
}

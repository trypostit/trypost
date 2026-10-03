<?php

declare(strict_types=1);

namespace App\Services\Media\Sources;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Models\Idea;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Photos Picker API sessions. The user picks up to `max_items` items
 * and confirms with Google's "Done"; each item becomes its own import. The
 * `photospicker.mediaitems.readonly` token lives only in the cache,
 * encrypted and bound to the user and session, for at most an hour, and is
 * dropped with the session once the last item's import finished.
 */
class GooglePhotosPicker
{
    public const string SESSION_ID_PATTERN = '/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/';

    public const string SESSION_EXPIRED = 'session_expired';

    public const string VIDEO_NOT_READY = 'video_not_ready';

    private const int MAX_TOKEN_TTL_SECONDS = 3600;

    private const int REQUEST_TIMEOUT_SECONDS = 20;

    private const int DEFAULT_POLL_INTERVAL_MS = 5000;

    private const int DEFAULT_POLL_TIMEOUT_MS = 600000;

    private const int MEDIA_ITEMS_PAGE_SIZE = 100;

    private const int EXTRA_PAGES = 1;

    /**
     * @return array{id: string, picker_uri: string, polling: array{interval_ms: int, timeout_ms: int}}
     */
    public function createSession(string $accessToken): array
    {
        $response = $this->client($accessToken)->post('sessions', [
            'pickingConfig' => ['maxItemCount' => self::maxItems()],
        ]);

        $id = (string) $response->json('id');
        $pickerUri = (string) $response->json('pickerUri');

        if (! $response->successful() || preg_match(self::SESSION_ID_PATTERN, $id) !== 1 || $pickerUri === '') {
            throw new RuntimeException("Google Photos session create failed ({$response->status()}).");
        }

        return [
            'id' => $id,
            'picker_uri' => $pickerUri,
            'polling' => $this->polling((array) $response->json('pollingConfig')),
        ];
    }

    /**
     * @return array{media_items_set: bool, polling: array{interval_ms: int, timeout_ms: int}}
     */
    public function session(string $sessionId, string $accessToken): array
    {
        $response = $this->client($accessToken)->get('sessions/'.rawurlencode($sessionId));

        if (! $response->successful()) {
            throw new RuntimeException("Google Photos session read failed ({$response->status()}).");
        }

        return [
            'media_items_set' => (bool) $response->json('mediaItemsSet'),
            'polling' => $this->polling((array) $response->json('pollingConfig')),
        ];
    }

    /**
     * How many items one pick may hold: the configured count, never above
     * what an idea's media tray accepts.
     */
    public static function maxItems(): int
    {
        return max(1, min((int) config('trypost.media_sources.google_photos.max_items'), Idea::MAX_MEDIA));
    }

    /**
     * Every picked item in the user's order, each as a download (without
     * the token, which the import job adds) or a failure reason. Photos are
     * fetched with `=d`, videos with `=dv` once Google finished processing.
     * Null when the session cannot be read or holds nothing.
     *
     * @return list<RemoteFile|string>|null
     */
    public function pickedFiles(string $sessionId, string $accessToken): ?array
    {
        $files = [];
        $pageToken = null;
        $pagesLeft = intdiv(self::maxItems() + self::MEDIA_ITEMS_PAGE_SIZE - 1, self::MEDIA_ITEMS_PAGE_SIZE) + self::EXTRA_PAGES;

        do {
            $response = $this->client($accessToken)->get('mediaItems', array_filter([
                'sessionId' => $sessionId,
                'pageSize' => self::MEDIA_ITEMS_PAGE_SIZE,
                'pageToken' => $pageToken,
            ]));

            if (! $response->successful()) {
                return null;
            }

            $items = (array) $response->json('mediaItems', []);

            foreach ($items as $item) {
                $files[] = $this->toRemoteFile((array) $item);
            }

            $pageToken = $response->json('nextPageToken');
            $pagesLeft--;
        } while ($items !== [] && $pagesLeft > 0 && is_string($pageToken) && $pageToken !== '' && count($files) < self::maxItems());

        return $files === [] ? null : array_slice($files, 0, self::maxItems());
    }

    /**
     * @return array<string, string>
     */
    public static function authorization(string $accessToken): array
    {
        return ['Authorization' => "Bearer {$accessToken}"];
    }

    /**
     * Counts the imports still to run for a session, so the last one to
     * finish deletes it.
     */
    public static function trackImports(string $userId, string $sessionId, int $count): void
    {
        Cache::put(self::pendingKey($userId, $sessionId), $count, self::MAX_TOKEN_TTL_SECONDS);
    }

    /**
     * One import of the session finished; the last one deletes the session
     * at Google and forgets its token. Releasing the same import twice (its
     * job's `finally` and then `failed()`) counts once.
     */
    public function releaseImport(string $userId, string $sessionId, string $importId): void
    {
        if (! Cache::add("google-photos-released:{$importId}", true, self::MAX_TOKEN_TTL_SECONDS)) {
            return;
        }

        if (Cache::decrement(self::pendingKey($userId, $sessionId)) > 0) {
            return;
        }

        $this->discard($userId, $sessionId);
    }

    /**
     * Deletes the session at Google and forgets its token and counter.
     */
    public function discard(string $userId, string $sessionId): void
    {
        $accessToken = self::token($userId, $sessionId);

        self::forget($userId, $sessionId);
        Cache::forget(self::pendingKey($userId, $sessionId));

        if ($accessToken !== null) {
            rescue(fn () => $this->deleteSession($sessionId, $accessToken), report: false);
        }
    }

    public function deleteSession(string $sessionId, string $accessToken): void
    {
        $this->client($accessToken)->delete('sessions/'.rawurlencode($sessionId));
    }

    public static function rememberToken(string $userId, string $sessionId, string $accessToken, int $expiresIn): void
    {
        Cache::put(
            self::cacheKey($userId, $sessionId),
            Crypt::encryptString($accessToken),
            max(1, min($expiresIn, self::MAX_TOKEN_TTL_SECONDS)),
        );
    }

    public static function token(string $userId, string $sessionId): ?string
    {
        $encrypted = Cache::get(self::cacheKey($userId, $sessionId));

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    public static function forget(string $userId, string $sessionId): void
    {
        Cache::forget(self::cacheKey($userId, $sessionId));
    }

    public static function cacheKey(string $userId, string $sessionId): string
    {
        return "google-photos:{$userId}:{$sessionId}";
    }

    private static function pendingKey(string $userId, string $sessionId): string
    {
        return "google-photos-pending:{$userId}:{$sessionId}";
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toRemoteFile(array $item): RemoteFile|string
    {
        $baseUrl = (string) data_get($item, 'mediaFile.baseUrl', '');

        if ($baseUrl === '') {
            return self::SESSION_EXPIRED;
        }

        $isVideo = data_get($item, 'type') === 'VIDEO';

        if ($isVideo && data_get($item, 'mediaFile.mediaFileMetadata.videoMetadata.processingStatus') !== 'READY') {
            return self::VIDEO_NOT_READY;
        }

        return new RemoteFile(
            $baseUrl.($isVideo ? '=dv' : '=d'),
            (string) data_get($item, 'mediaFile.filename', ''),
            [],
            Source::GooglePhotos->downloadHosts(),
            Source::GooglePhotos,
            ['media_item_id' => (string) data_get($item, 'id', '')],
        );
    }

    private function client(string $accessToken): PendingRequest
    {
        $api = rtrim((string) config('trypost.media_sources.google_photos.api'), '/');

        return Http::baseUrl("{$api}/v1/")
            ->withToken($accessToken)
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }

    /**
     * Google sends durations as strings such as "3.5s".
     *
     * @param  array<string, mixed>  $config
     * @return array{interval_ms: int, timeout_ms: int}
     */
    private function polling(array $config): array
    {
        return [
            'interval_ms' => $this->milliseconds(data_get($config, 'pollInterval'), self::DEFAULT_POLL_INTERVAL_MS),
            'timeout_ms' => $this->milliseconds(data_get($config, 'timeoutIn'), self::DEFAULT_POLL_TIMEOUT_MS),
        ];
    }

    private function milliseconds(mixed $duration, int $default): int
    {
        if (! is_string($duration) || preg_match('/^(\d+(?:\.\d+)?)s$/', $duration, $matches) !== 1) {
            return $default;
        }

        return max(1000, (int) round((float) $matches[1] * 1000));
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Support\Social\InstagramAudio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Audio an Instagram account connected through Facebook may attach to a reel,
 * read from the Instagram Audio API, which lists them under `audio`: music
 * then original sounds (or one of the two catalogues), trending or matching a
 * search. Each answer is kept for ten minutes per account, catalogue and query,
 * and one with a catalogue missing for a minute.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/content-publishing/audio-api/
 */
class SearchInstagramAudio
{
    /** Original sounds per search whose creator picture is looked up. */
    private const int PICTURE_LOOKUPS = 10;

    /**
     * @param  'music'|'original_sound'|null  $type  one catalogue, or both when null
     * @return list<array{id: string, type: string|null, title: string, artist: string|null, cover_url: string|null, duration_ms: int|null, preview_url: string|null}>
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws ThrottleRequestsException
     */
    public static function execute(SocialAccount $account, ?string $query = null, ?string $type = null): array
    {
        $query = Str::squish((string) $query);
        $scope = $type ?? 'all';
        $queryHash = md5(Str::lower($query));
        $cacheKey = "instagram:audio-search:{$account->id}:{$scope}:{$queryHash}";

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        InstagramAudio::spend($account);

        [$tracks, $complete] = self::search($account, $query, $type === null ? InstagramAudio::TYPES : [$type]);

        Cache::put($cacheKey, $tracks, $complete ? now()->addMinutes(10) : now()->addMinute());

        return $tracks;
    }

    /**
     * The tracks found and whether every catalogue answered. A catalogue that
     * failed leaves the answer partial, so it is kept for a minute only.
     *
     * @param  list<string>  $types
     * @return array{0: list<array{id: string, type: string|null, title: string, artist: string|null, cover_url: string|null, duration_ms: int|null, preview_url: string|null}>, 1: bool}
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    private static function search(SocialAccount $account, string $query, array $types): array
    {
        $url = "{$account->platform->graphApiUrl()}/ig_audio";

        $responses = Http::pool(fn (Pool $pool): array => collect($types)
            ->map(fn (string $type) => $pool->as($type)
                ->connectTimeout(3)
                ->timeout(8)
                ->get($url, array_filter([
                    'audio_type' => $type,
                    'user_id' => $account->platform_user_id,
                    'search_query' => $query !== '' ? $query : null,
                    'access_token' => $account->access_token,
                ])))
            ->all());

        $succeeded = collect($responses)->filter(fn (mixed $response): bool => $response instanceof Response && $response->successful());

        if ($succeeded->isEmpty()) {
            $failed = collect($responses)->first();

            throw $failed instanceof Response ? $failed->toException() ?? new ConnectionException('Instagram audio search failed.') : $failed;
        }

        $tracks = fn (string $type): Collection => Collection::make((array) data_get($succeeded->get($type)?->json(), 'audio', []))
            ->map(fn (mixed $track): array => InstagramAudio::present($track))
            ->filter(fn (array $track): bool => data_get($track, 'id') !== '');

        $music = $query === ''
            ? $tracks('music')
            : $tracks('music')->filter(fn (array $track): bool => self::matches($track, $query));

        $found = $music
            ->concat(self::withCreatorPictures($account, $tracks('original_sound')))
            ->unique('id')
            ->values()
            ->all();

        return [$found, $succeeded->count() === count($types)];
    }

    /**
     * The search lists an original sound without any picture; its own lookup
     * carries the creator's profile picture, which stands in for the cover.
     * Only the first few are looked up, together. A picture is shared by every
     * account and kept for a few hours, as its URL expires; a failed lookup may
     * be this account's token, so only this account skips it, for a few minutes.
     *
     * @param  Collection<int, array{id: string, type: string|null, title: string, artist: string|null, cover_url: string|null, duration_ms: int|null, preview_url: string|null}>  $sounds
     * @return Collection<int, array{id: string, type: string|null, title: string, artist: string|null, cover_url: string|null, duration_ms: int|null, preview_url: string|null}>
     */
    private static function withCreatorPictures(SocialAccount $account, Collection $sounds): Collection
    {
        $missing = $sounds
            ->take(self::PICTURE_LOOKUPS)
            ->filter(fn (array $sound): bool => blank(data_get($sound, 'cover_url'))
                && ! Cache::has(self::pictureCacheKey(data_get($sound, 'id')))
                && ! Cache::has(self::failedPictureCacheKey($account, data_get($sound, 'id'))))
            ->pluck('id');

        if ($missing->isNotEmpty()) {
            $responses = Http::pool(fn (Pool $pool): array => $missing
                ->map(fn (string $id) => $pool->as($id)
                    ->connectTimeout(3)
                    ->timeout(5)
                    ->get("{$account->platform->graphApiUrl()}/{$id}", [
                        'user_id' => $account->platform_user_id,
                        'access_token' => $account->access_token,
                    ]))
                ->all());

            collect($responses)->each(function (mixed $response, string $id) use ($account): void {
                if ($response instanceof Response && $response->successful()) {
                    Cache::put(self::pictureCacheKey($id), (string) data_get($response->json(), 'profile_picture_url', ''), now()->addHours(6));

                    return;
                }

                Cache::put(self::failedPictureCacheKey($account, $id), true, now()->addMinutes(10));
            });
        }

        return $sounds->map(fn (array $sound): array => [
            ...$sound,
            'cover_url' => data_get($sound, 'cover_url') ?? (filled($picture = Cache::get(self::pictureCacheKey(data_get($sound, 'id')))) ? $picture : null),
        ]);
    }

    private static function pictureCacheKey(string $audioId): string
    {
        return "instagram:audio-picture:{$audioId}";
    }

    private static function failedPictureCacheKey(SocialAccount $account, string $audioId): string
    {
        return "instagram:audio-picture-failed:{$account->id}:{$audioId}";
    }

    /**
     * The music search answers with the closest tracks of the catalogue licensed
     * to third parties, related or not, when the searched artist is not in it;
     * only a track naming every searched word is kept. A word matches as typed
     * or without accents, so "cafe" finds "Café" and "夜に駆ける" still counts.
     *
     * @param  array{title: string, artist: string|null}  $track
     */
    private static function matches(array $track, string $query): bool
    {
        $title = data_get($track, 'title');
        $artist = data_get($track, 'artist');
        $haystack = Str::lower("{$title} {$artist}");
        $asciiHaystack = Str::lower(Str::ascii($haystack));

        return collect(preg_split('/\s+/u', Str::lower($query)) ?: [])
            ->filter()
            ->every(fn (string $word): bool => str_contains($haystack, $word)
                || (($asciiWord = Str::ascii($word)) !== '' && str_contains($asciiHaystack, $asciiWord)));
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GIF search for the composer's GIPHY picker. Results are the same for every
 * user, so each page is cached for a few minutes.
 *
 * @see https://developers.giphy.com/docs/api/endpoint
 */
class GiphyService
{
    private const int CACHE_MINUTES = 10;

    private const int TIMEOUT_SECONDS = 10;

    /** GIPHY refuses an offset past this one. */
    private const int MAX_OFFSET = 4999;

    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('trypost.media_sources.giphy.api');
        $this->apiKey = (string) config('services.giphy.api_key', '');
    }

    /** GIPHY needs its flag on and an API key. */
    public static function isEnabled(): bool
    {
        return (bool) config('trypost.media_sources.giphy.enabled')
            && filled(config('services.giphy.api_key'));
    }

    /**
     * Null when GIPHY could not answer (unreachable, rate limited, rejected).
     *
     * @return array{results: list<array<string, mixed>>, has_more: bool}|null
     */
    public function search(string $query, int $page = 1): ?array
    {
        $term = Str::lower(Str::trim($query));
        $hash = md5($term);

        return $this->page("search:{$page}:{$hash}", 'gifs/search', ['q' => $term], $page);
    }

    /**
     * Null when GIPHY could not answer.
     *
     * @return array{results: list<array<string, mixed>>, has_more: bool}|null
     */
    public function trending(int $page = 1): ?array
    {
        return $this->page("trending:{$page}", 'gifs/trending', [], $page);
    }

    /**
     * @param  array<string, string>  $query
     * @return array{results: list<array<string, mixed>>, has_more: bool}|null
     */
    private function page(string $key, string $path, array $query, int $page): ?array
    {
        if (! self::isEnabled()) {
            return ['results' => [], 'has_more' => false];
        }

        $offset = ($page - 1) * $this->perPage();

        return $this->remember("{$key}:{$this->perPage()}", function () use ($path, $query, $offset): ?array {
            $data = $this->fetch($path, [
                ...$query,
                'limit' => $this->perPage(),
                'offset' => $offset,
                'rating' => config('trypost.media_sources.giphy.rating'),
            ]);

            if (blank($data)) {
                return null;
            }

            $count = (int) data_get($data, 'pagination.count', 0);
            $next = $offset + $count;

            return [
                'results' => collect(data_get($data, 'data', []))
                    ->filter(fn (mixed $gif): bool => Arr::accessible($gif) && filled(data_get($gif, 'images.downsized_medium.url')))
                    ->map(fn (array $gif): array => $this->formatGif($gif))
                    ->values()
                    ->all(),
                'has_more' => $this->hasMore($count, $next, (int) data_get($data, 'pagination.total_count', 0)),
            ];
        });
    }

    /**
     * Whether another page follows. GIPHY's `total_count` runs ahead of what a
     * search can reach, so an empty page ends the list whatever it says.
     */
    private function hasMore(int $count, int $next, int $total): bool
    {
        if ($count === 0 || $next > self::MAX_OFFSET) {
            return false;
        }

        return $next < $total;
    }

    /**
     * @param  Closure(): ?array<mixed>  $load
     * @return array<mixed>|null
     */
    private function remember(string $key, Closure $load): ?array
    {
        $cacheKey = "giphy:{$key}";
        $cached = Cache::get($cacheKey);

        if (filled($cached)) {
            return $cached;
        }

        $fresh = $load();

        if (filled($fresh)) {
            Cache::put($cacheKey, $fresh, now()->addMinutes(self::CACHE_MINUTES));
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    private function fetch(string $path, array $query): ?array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get("{$this->baseUrl}/{$path}", [...$query, 'api_key' => $this->apiKey]);
        } catch (ConnectionException $exception) {
            Log::warning('GIPHY request failed', ['path' => $path, 'error' => $exception->getMessage()]);

            return null;
        }

        $data = $response->json();

        if ($response->failed() || ! Arr::accessible($data)) {
            Log::warning('GIPHY request failed', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $data;
    }

    /**
     * A GIF as the picker shows it: a light preview, and the rendition the
     * post takes (`downsized_medium`, which GIPHY keeps under 5 MB, the
     * smallest GIF cap of the networks that accept one).
     *
     * @param  array<string, mixed>  $gif
     * @return array<string, mixed>
     */
    private function formatGif(array $gif): array
    {
        return [
            'id' => (string) data_get($gif, 'id'),
            'title' => data_get($gif, 'title'),
            'url_preview' => data_get($gif, 'images.fixed_width.url'),
            'url' => data_get($gif, 'images.downsized_medium.url'),
            'width' => (int) data_get($gif, 'images.fixed_width.width'),
            'height' => (int) data_get($gif, 'images.fixed_width.height'),
            'giphy_url' => data_get($gif, 'url'),
        ];
    }

    private function perPage(): int
    {
        return (int) config('app.pagination.default');
    }
}

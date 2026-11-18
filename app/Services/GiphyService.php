<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Media\Source;
use Closure;
use Illuminate\Http\Client\ConnectionException;
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

    /** GIPHY refuses an offset past this one. */
    private const int MAX_OFFSET = 4999;

    private string $baseUrl;

    private string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('trypost.media_sources.giphy.api');
        $this->apiKey = (string) config('services.giphy.api_key', '');
    }

    /**
     * Null when GIPHY could not answer (unreachable, rate limited, rejected).
     *
     * @return array{results: list<array<string, mixed>>, has_more: bool}|null
     */
    public function search(string $query, int $page = 1): ?array
    {
        $term = Str::lower(Str::trim($query));

        return $this->page("search:{$page}:".md5($term), 'gifs/search', ['q' => $term], $page);
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
        if (! Source::Giphy->isEnabled()) {
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

            if ($data === null) {
                return null;
            }

            $next = $offset + (int) data_get($data, 'pagination.count', 0);

            return [
                'results' => collect(data_get($data, 'data', []))
                    ->filter(fn (mixed $gif): bool => is_array($gif) && filled(data_get($gif, 'images.downsized_medium.url')))
                    ->map(fn (array $gif): array => $this->formatGif($gif))
                    ->values()
                    ->all(),
                'has_more' => $next < (int) data_get($data, 'pagination.total_count', 0) && $next <= self::MAX_OFFSET,
            ];
        });
    }

    /**
     * @param  Closure(): ?array<mixed>  $load
     * @return array<mixed>|null
     */
    private function remember(string $key, Closure $load): ?array
    {
        $cacheKey = "giphy:{$key}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $load();

        if ($fresh !== null) {
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
            $response = Http::timeout(10)->get("{$this->baseUrl}/{$path}", [...$query, 'api_key' => $this->apiKey]);
        } catch (ConnectionException $exception) {
            Log::warning('GIPHY request failed', ['path' => $path, 'error' => $exception->getMessage()]);

            return null;
        }

        $data = $response->json();

        if ($response->failed() || ! is_array($data)) {
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
            'author' => $this->author($gif),
        ];
    }

    /**
     * @param  array<string, mixed>  $gif
     * @return array{name: string, url: ?string}|null
     */
    private function author(array $gif): ?array
    {
        $name = data_get($gif, 'user.display_name');

        if (blank($name)) {
            return null;
        }

        return ['name' => (string) $name, 'url' => data_get($gif, 'user.profile_url')];
    }

    private function perPage(): int
    {
        return (int) config('app.pagination.default');
    }
}

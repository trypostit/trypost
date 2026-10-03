<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UnsplashService
{
    private const string REFERRAL_QUERY = 'utm_source=trypost&utm_medium=referral';

    private const int CACHE_MINUTES = 10;

    private string $baseUrl;

    private string $accessKey;

    public function __construct()
    {
        $this->baseUrl = (string) config('trypost.media_sources.unsplash.api');
        $this->accessKey = (string) config('services.unsplash.access_key', '');
    }

    /**
     * Null when Unsplash could not answer (unreachable, rate limited, rejected).
     *
     * @return array{results: array<int, array<string, mixed>>, total: int, total_pages: int}|null
     */
    public function search(string $query, int $page = 1): ?array
    {
        if (empty($this->accessKey)) {
            return ['results' => [], 'total' => 0, 'total_pages' => 0];
        }

        $term = Str::lower(trim($query));

        return $this->remember("search:{$page}:{$this->perPage()}:".md5($term), function () use ($term, $page): ?array {
            $data = $this->fetch('search/photos', [
                'query' => $term,
                'page' => $page,
                'per_page' => $this->perPage(),
            ]);

            return $data === null ? null : [
                'results' => collect(data_get($data, 'results', []))->map(fn (array $photo) => $this->formatPhoto($photo))->all(),
                'total' => (int) data_get($data, 'total', 0),
                'total_pages' => (int) data_get($data, 'total_pages', 0),
            ];
        });
    }

    /**
     * The same for every user, so a page is cached and shared. Null when
     * Unsplash could not answer.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function trending(int $page = 1): ?array
    {
        if (empty($this->accessKey)) {
            return [];
        }

        return $this->remember("trending:{$page}:{$this->perPage()}", function () use ($page): ?array {
            $data = $this->fetch('photos', [
                'page' => $page,
                'per_page' => $this->perPage(),
                'order_by' => 'popular',
            ]);

            return $data === null ? null : collect($data)->map(fn (array $photo) => $this->formatPhoto($photo))->all();
        });
    }

    /**
     * @param  Closure(): ?array<mixed>  $load
     * @return array<mixed>|null
     */
    private function remember(string $key, Closure $load): ?array
    {
        $cacheKey = "unsplash:{$key}";
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
            $response = Http::timeout(10)
                ->withHeaders(['Authorization' => "Client-ID {$this->accessKey}"])
                ->get("{$this->baseUrl}/{$path}", $query);
        } catch (ConnectionException $exception) {
            Log::warning('Unsplash request failed', ['path' => $path, 'error' => $exception->getMessage()]);

            return null;
        }

        $data = $response->json();

        if ($response->failed() || ! is_array($data)) {
            Log::warning('Unsplash request failed', ['path' => $path, 'status' => $response->status()]);

            return null;
        }

        return $data;
    }

    /**
     * Unsplash's download event for a photo the user picked; a failure never
     * undoes the pick.
     */
    public function trackDownload(string $downloadLocation): void
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['Authorization' => "Client-ID {$this->accessKey}"])
                ->get($downloadLocation);
        } catch (ConnectionException $exception) {
            Log::warning('Unsplash download tracking failed', ['error' => $exception->getMessage()]);

            return;
        }

        if ($response->failed()) {
            Log::warning('Unsplash download tracking failed', ['status' => $response->status()]);
        }
    }

    /**
     * @param  array<string, mixed>  $photo
     * @return array<string, mixed>
     */
    private function formatPhoto(array $photo): array
    {
        return [
            'id' => data_get($photo, 'id'),
            'url_small' => data_get($photo, 'urls.small'),
            'url_regular' => data_get($photo, 'urls.regular'),
            'url_full' => data_get($photo, 'urls.full'),
            'download_location' => data_get($photo, 'links.download_location'),
            'description' => data_get($photo, 'alt_description'),
            'width' => data_get($photo, 'width'),
            'height' => data_get($photo, 'height'),
            'author' => [
                'name' => data_get($photo, 'user.name'),
                'url' => $this->withReferral((string) data_get($photo, 'user.links.html')),
            ],
            'unsplash_url' => $this->withReferral($this->website()),
        ];
    }

    private function website(): string
    {
        $website = rtrim((string) config('trypost.media_sources.unsplash.website'), '/');

        return "{$website}/";
    }

    private function withReferral(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        $referral = self::REFERRAL_QUERY;

        return "{$url}{$separator}{$referral}";
    }

    private function perPage(): int
    {
        return (int) config('app.pagination.default');
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UnsplashService
{
    private string $baseUrl = 'https://api.unsplash.com';

    private string $accessKey;

    public function __construct()
    {
        $this->accessKey = (string) config('services.unsplash.access_key', '');
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, total: int, total_pages: int}
     */
    public function search(string $query, int $page = 1): array
    {
        if (empty($this->accessKey)) {
            return ['results' => [], 'total' => 0, 'total_pages' => 0];
        }

        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => "Client-ID {$this->accessKey}"])
            ->get("{$this->baseUrl}/search/photos", [
                'query' => $query,
                'page' => $page,
                'per_page' => config('app.pagination.default'),
                'orientation' => 'landscape',
            ]);

        if ($response->failed()) {
            Log::warning('Unsplash search failed', ['body' => $response->body()]);

            return ['results' => [], 'total' => 0, 'total_pages' => 0];
        }

        $data = $response->json();

        return [
            'results' => collect(data_get($data, 'results', []))->map(fn (array $photo) => $this->formatPhoto($photo))->all(),
            'total' => data_get($data, 'total', 0),
            'total_pages' => data_get($data, 'total_pages', 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function trending(int $page = 1): array
    {
        if (empty($this->accessKey)) {
            return [];
        }

        $response = Http::timeout(10)
            ->withHeaders(['Authorization' => "Client-ID {$this->accessKey}"])
            ->get("{$this->baseUrl}/photos", [
                'page' => $page,
                'per_page' => config('app.pagination.default'),
                'order_by' => 'popular',
            ]);

        if ($response->failed()) {
            Log::warning('Unsplash trending fetch failed', ['body' => $response->body()]);

            return [];
        }

        return collect($response->json())->map(fn (array $photo) => $this->formatPhoto($photo))->all();
    }

    public function trackDownload(string $downloadLocation): void
    {
        Http::timeout(5)
            ->withHeaders(['Authorization' => "Client-ID {$this->accessKey}"])
            ->get($downloadLocation);
    }

    /**
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
                'url' => data_get($photo, 'user.links.html'),
            ],
        ];
    }
}

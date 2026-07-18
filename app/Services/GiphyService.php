<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GiphyService
{
    private string $baseUrl = 'https://api.giphy.com/v1/gifs';

    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = (string) config('services.giphy.api_key', '');
    }

    /**
     * @return array{results: array<int, array<string, mixed>>, total: int, total_pages: int}
     */
    public function search(string $query, int $page = 1): array
    {
        if (empty($this->apiKey)) {
            return ['results' => [], 'total' => 0, 'total_pages' => 0];
        }

        $perPage = (int) config('app.pagination.default');
        $offset = ($page - 1) * $perPage;

        $response = Http::timeout(10)
            ->get("{$this->baseUrl}/search", [
                'api_key' => $this->apiKey,
                'q' => $query,
                'limit' => $perPage,
                'offset' => $offset,
                'rating' => 'g',
                'lang' => 'en',
            ]);

        if ($response->failed()) {
            Log::warning('Giphy search failed', ['body' => $response->body()]);

            return ['results' => [], 'total' => 0, 'total_pages' => 0];
        }

        $data = $response->json();
        $total = data_get($data, 'pagination.total_count', 0);

        return [
            'results' => collect(data_get($data, 'data', []))->map(fn (array $gif) => $this->formatGif($gif))->all(),
            'total' => $total,
            'total_pages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function trending(int $page = 1): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $perPage = (int) config('app.pagination.default');
        $offset = ($page - 1) * $perPage;

        $response = Http::timeout(10)
            ->get("{$this->baseUrl}/trending", [
                'api_key' => $this->apiKey,
                'limit' => $perPage,
                'offset' => $offset,
                'rating' => 'g',
            ]);

        if ($response->failed()) {
            Log::warning('Giphy trending fetch failed', ['body' => $response->body()]);

            return [];
        }

        return collect(data_get($response->json(), 'data', []))->map(fn (array $gif) => $this->formatGif($gif))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatGif(array $gif): array
    {
        return [
            'id' => data_get($gif, 'id'),
            'title' => data_get($gif, 'title'),
            'url_preview' => data_get($gif, 'images.fixed_width.url'),
            'url_original' => data_get($gif, 'images.original.url'),
            'url_downsized' => data_get($gif, 'images.downsized_medium.url'),
            'width' => (int) data_get($gif, 'images.original.width', 0),
            'height' => (int) data_get($gif, 'images.original.height', 0),
            'size' => (int) data_get($gif, 'images.original.size', 0),
        ];
    }
}

<?php

declare(strict_types=1);

use App\Services\Brand\SafeHttpFetcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (! function_exists('uploadFromUrl')) {
    /**
     * Download an image from URL and upload to storage.
     *
     * @param  string|null  $url  The URL to download from
     * @param  string  $directory  The directory to store the file in
     * @return string|null The stored file path or null on failure
     */
    function uploadFromUrl(?string $url, string $directory = 'social-accounts'): ?string
    {
        if (! $url) {
            return null;
        }

        try {
            $response = app(SafeHttpFetcher::class)->guardedRequest($url)->timeout(10)->get($url);

            if (! $response->successful()) {
                Log::warning('uploadFromUrl: Failed to download', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $contentType = $response->header('Content-Type') ?? 'image/jpeg';
            $extension = match (true) {
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'gif') => 'gif',
                str_contains($contentType, 'webp') => 'webp',
                default => 'jpg',
            };

            $filename = sprintf(
                '%s/%s.%s',
                trim($directory, '/'),
                Str::uuid(),
                $extension
            );

            Storage::put($filename, $response->body(), 'public');

            return $filename;
        } catch (Exception $e) {
            Log::warning('uploadFromUrl: Exception', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

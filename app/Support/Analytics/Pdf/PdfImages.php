<?php

declare(strict_types=1);

namespace App\Support\Analytics\Pdf;

use App\Services\Http\SafeHttpFetcher;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Inlines the avatars and post thumbnails the PDF shows, shrunk to the size they
 * are printed at, so the PDF stays small and Chromium loads no image. Our own
 * media is read from the disk; anything else goes through the SSRF-guarded
 * fetcher. An image that fails to load is left out instead of printed broken.
 */
class PdfImages
{
    private const int MAX_BYTES = 8 * 1024 * 1024;

    /** @var array<string, string|null> */
    private array $inlined = [];

    private ?ImageManager $manager = null;

    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

    public function remote(?string $url, int $size): ?string
    {
        if (blank($url)) {
            return null;
        }

        return $this->remember("remote:{$size}:{$url}", fn (): ?string => $this->fetcher->tryGetBody((string) $url, self::MAX_BYTES), $size);
    }

    public function stored(?string $path, int $size): ?string
    {
        if (blank($path)) {
            return null;
        }

        return $this->remember("stored:{$size}:{$path}", fn (): ?string => Storage::exists((string) $path) ? Storage::get((string) $path) : null, $size);
    }

    /**
     * @param  callable(): (string|null)  $read
     */
    private function remember(string $key, callable $read, int $size): ?string
    {
        if (array_key_exists($key, $this->inlined)) {
            return $this->inlined[$key];
        }

        return $this->inlined[$key] = $this->shrink($read(), $size);
    }

    private function shrink(?string $bytes, int $size): ?string
    {
        if (blank($bytes) || strlen((string) $bytes) > self::MAX_BYTES) {
            return null;
        }

        try {
            $this->manager ??= new ImageManager(new Driver);

            return (string) $this->manager->decodeBinary((string) $bytes)
                ->cover($size, $size)
                ->encode(new JpegEncoder(quality: 82))
                ->toDataUri();
        } catch (Throwable) {
            return null;
        }
    }
}

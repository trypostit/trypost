<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Enums\Media\Type;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use RuntimeException;

trait HasMedia
{
    /**
     * Media collections configuration.
     * 'single' = only one media per collection (replaces existing)
     * 'multiple' = unlimited media per collection
     */
    protected static array $mediaCollections = [
        Workspace::class => [
            'logo' => 'single',
            'assets' => 'multiple',
        ],
        User::class => [
            'avatar' => 'single',
        ],
    ];

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('order');
    }

    public function getMedia(string $collection = 'default'): MorphMany
    {
        return $this->media()->where('collection', $collection);
    }

    public function getFirstMedia(string $collection = 'default'): ?Media
    {
        return $this->getMedia($collection)->first();
    }

    public function getFirstMediaUrl(string $collection = 'default', ?string $default = null): ?string
    {
        $media = $this->getFirstMedia($collection);

        return $media?->url ?? $default;
    }

    /**
     * Generate a DiceBear fallback URL using initials.
     */
    public function getFallbackAvatarUrl(string $seed): string
    {
        return 'https://api.dicebear.com/9.x/initials/svg?backgroundColor=777777&fontFamily=Verdana&fontSize=40&seed='.urlencode($seed);
    }

    /**
     * Add media to a collection.
     * If the collection is configured as 'single', it will clear existing media first.
     */
    public function addMedia(UploadedFile $file, string $collection = 'default', array $meta = [], ?string $groupId = null): Media
    {
        if ($this->isSingleMediaCollection($collection)) {
            $this->clearMediaCollection($collection);
        }

        $mimeType = $file->getMimeType();
        $type = $this->getMediaType($mimeType);

        // Normalize non-JPEG still images to JPEG q100 for universal platform compatibility.
        // GIF is preserved (animation kept for X/Bluesky/Mastodon).
        [$normalizedBytes, $normalizedMime, $normalizedExt] = $this->normalizeImageFormat(
            $file->getPathname(),
            $mimeType,
            $type,
            $file->getClientOriginalExtension(),
        );

        $filename = Str::uuid().'.'.$normalizedExt;
        $path = "medias/{$filename}";

        Storage::put($path, $normalizedBytes);

        return $this->media()->create([
            'group_id' => $groupId ?? Str::uuid()->toString(),
            'collection' => $collection,
            'type' => $type,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $normalizedMime,
            'size' => strlen($normalizedBytes),
            'order' => 0,
            'meta' => array_merge($this->getMediaMetaFromBytes($normalizedBytes, $type, $meta), $meta),
        ]);
    }

    /**
     * Add media from a file path (used for chunked uploads).
     * Images are normalized in memory; videos/PDFs are streamed to storage.
     */
    public function addMediaFromPath(string $filePath, string $originalFilename, string $collection = 'default', array $meta = [], ?string $groupId = null): Media
    {
        if ($this->isSingleMediaCollection($collection)) {
            $this->clearMediaCollection($collection);
        }

        $mimeType = mime_content_type($filePath);
        $type = $this->getMediaType($mimeType);
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);

        $stored = $type === Type::Image->value
            ? $this->storeImageFromPath($filePath, $mimeType, $type, $extension, $meta)
            : $this->streamFileToStorage($filePath, $mimeType, $extension, $meta);

        return $this->media()->create([
            'group_id' => $groupId ?? Str::uuid()->toString(),
            'collection' => $collection,
            'type' => $type,
            'path' => $stored['path'],
            'original_filename' => $originalFilename,
            'mime_type' => $stored['mime_type'],
            'size' => $stored['size'],
            'order' => 0,
            'meta' => $stored['meta'],
        ]);
    }

    /**
     * Register media that is already stored on the default disk (e.g. after a
     * multipart cloud upload).
     */
    public function addMediaFromStoredPath(
        string $storagePath,
        string $originalFilename,
        string $mimeType,
        int $size,
        string $collection = 'default',
        array $meta = [],
        ?string $groupId = null,
    ): Media {
        if ($this->isSingleMediaCollection($collection)) {
            $this->clearMediaCollection($collection);
        }

        $type = $this->getMediaType($mimeType);

        return $this->media()->create([
            'group_id' => $groupId ?? Str::uuid()->toString(),
            'collection' => $collection,
            'type' => $type,
            'path' => $storagePath,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'size' => $size,
            'order' => 0,
            'meta' => $meta,
        ]);
    }

    public function clearMediaCollection(string $collection = 'default'): void
    {
        $this->getMedia($collection)->each(fn (Media $media) => $media->delete());
    }

    public function isSingleMediaCollection(string $collection): bool
    {
        $modelClass = static::class;
        $config = self::$mediaCollections[$modelClass][$collection] ?? 'multiple';

        return $config === 'single';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{path: string, mime_type: string, size: int, meta: array<string, mixed>}
     */
    private function storeImageFromPath(string $filePath, string $mimeType, string $type, string $extension, array $meta): array
    {
        [$bytes, $storedMime, $storedExt] = $this->normalizeImageFormat(
            $filePath,
            $mimeType,
            $type,
            $extension,
        );

        $filename = Str::uuid().".{$storedExt}";
        $path = "medias/{$filename}";
        Storage::put($path, $bytes);

        return [
            'path' => $path,
            'mime_type' => $storedMime,
            'size' => strlen($bytes),
            'meta' => array_merge($this->getMediaMetaFromBytes($bytes, $type, $meta), $meta),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{path: string, mime_type: string, size: int, meta: array<string, mixed>}
     */
    private function streamFileToStorage(string $filePath, string $mimeType, string $extension, array $meta): array
    {
        $filename = Str::uuid().".{$extension}";
        $path = "medias/{$filename}";
        $stream = fopen($filePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open media file for reading: {$filePath}");
        }

        try {
            Storage::writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => (int) filesize($filePath),
            'meta' => $meta,
        ];
    }

    private function getMediaType(string $mimeType): string
    {
        return (Type::classify($mimeType)
            ?? throw new \InvalidArgumentException("Unsupported media MIME type: {$mimeType}"))->value;
    }

    private function getMediaMeta(UploadedFile $file, string $type): array
    {
        $meta = [];

        if ($type === 'image') {
            $imageInfo = @getimagesize($file->getPathname());
            if ($imageInfo) {
                $meta['width'] = $imageInfo[0];
                $meta['height'] = $imageInfo[1];
            }
        }

        return $meta;
    }

    /**
     * Extract width/height from raw image bytes (used after format normalization
     * when we no longer have the original file path).
     */
    private function getMediaMetaFromBytes(string $bytes, string $type, array $clientMeta = []): array
    {
        $meta = [];

        if ($type === 'image') {
            $imageInfo = @getimagesizefromstring($bytes);
            if ($imageInfo) {
                $meta['width'] = $imageInfo[0];
                $meta['height'] = $imageInfo[1];
            }
        }

        return $meta;
    }

    /**
     * Convert PNG/WebP/HEIC/AVIF to JPEG at q100 (keeps dimensions). GIF and
     * JPEG are returned untouched. Non-image types are passed through.
     *
     * @return array{0: string, 1: string, 2: string} [bytes, mime_type, extension]
     */
    private function normalizeImageFormat(string $filePath, string $mimeType, string $type, string $originalExtension): array
    {
        if ($type !== 'image') {
            return [file_get_contents($filePath), $mimeType, $originalExtension];
        }

        // Formats that publish safely everywhere (JPEG is universal, GIF needed for X/Bluesky/Mastodon).
        if (in_array($mimeType, ['image/jpeg', 'image/jpg', 'image/gif'], true)) {
            return [file_get_contents($filePath), $mimeType, $originalExtension];
        }

        try {
            $manager = new ImageManager(new Driver);
            $encoded = (string) $manager->decodePath($filePath)->encode(new JpegEncoder(quality: 100));

            return [$encoded, 'image/jpeg', 'jpg'];
        } catch (\Throwable $e) {
            Log::warning('HasMedia: image normalization failed, storing original', [
                'mime' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            return [file_get_contents($filePath), $mimeType, $originalExtension];
        }
    }
}

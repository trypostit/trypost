<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Enums\Media\Type;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $bytes = file_get_contents($file->getPathname());
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = 'medias/'.$filename;

        Storage::put($path, $bytes);

        return $this->media()->create([
            'group_id' => $groupId ?? Str::uuid()->toString(),
            'collection' => $collection,
            'type' => $type,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'order' => 0,
            'meta' => array_merge($this->getMediaMetaFromBytes($bytes, $type), $meta),
        ]);
    }

    /**
     * Add media from a file path (used for chunked uploads).
     */
    public function addMediaFromPath(string $filePath, string $originalFilename, string $collection = 'default', array $meta = [], ?string $groupId = null): Media
    {
        if ($this->isSingleMediaCollection($collection)) {
            $this->clearMediaCollection($collection);
        }

        $mimeType = mime_content_type($filePath);
        $type = $this->getMediaType($mimeType);
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $bytes = file_get_contents($filePath);
        $filename = Str::uuid().'.'.$extension;
        $storagePath = 'medias/'.$filename;

        Storage::put($storagePath, $bytes);

        return $this->media()->create([
            'group_id' => $groupId ?? Str::uuid()->toString(),
            'collection' => $collection,
            'type' => $type,
            'path' => $storagePath,
            'original_filename' => $originalFilename,
            'mime_type' => $mimeType,
            'size' => strlen($bytes),
            'order' => 0,
            'meta' => array_merge($this->getMediaMetaFromBytes($bytes, $type), $meta),
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

    private function getMediaType(string $mimeType): string
    {
        return (Type::classify($mimeType)
            ?? throw new \InvalidArgumentException("Unsupported media MIME type: {$mimeType}"))->value;
    }

    private function getMediaMetaFromBytes(string $bytes, string $type): array
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
}

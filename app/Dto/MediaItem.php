<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enums\Media\Source;
use App\Enums\Media\Type;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;

class MediaItem
{
    /**
     * @param  array<string, mixed>|null  $meta
     * @param  array<string, mixed>|null  $source_meta
     */
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $url,
        public readonly ?string $mime_type = null,
        public readonly ?string $original_filename = null,
        public readonly ?Source $source = null,
        public readonly ?array $source_meta = null,
        public readonly ?array $meta = null,
        public readonly ?Type $type = null,
        public readonly ?int $size = null,
    ) {}

    /**
     * The item to store in `posts.media` for an asset. Carries `meta` so the
     * publish-time checks can read the measured video duration; alt text only
     * applies to images.
     */
    public static function fromMedia(Media $media, ?string $alt = null): self
    {
        $meta = $media->meta ?? [];

        if (filled($alt) && $media->isImage()) {
            $meta['alt_text'] = $alt;
        }

        return new self(
            id: $media->id,
            path: $media->path,
            url: $media->url,
            mime_type: $media->mime_type,
            original_filename: $media->original_filename,
            meta: $meta ?: null,
            type: $media->type,
            size: $media->size,
        );
    }

    /**
     * The stored `posts.media` shape — the same keys the editor keeps from a
     * MediaResource response, so every attach flow writes the same item.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'url' => $this->url,
            'type' => $this->type?->value,
            'mime_type' => $this->mime_type,
            'original_filename' => $this->original_filename,
            'size' => $this->size,
            ...array_filter([
                'meta' => $this->meta,
                'source' => $this->source?->value,
                'source_meta' => $this->source_meta,
            ]),
        ];
    }

    public function isVideo(): bool
    {
        return $this->kind() === Type::Video;
    }

    public function isImage(): bool
    {
        return $this->kind() === Type::Image;
    }

    public function isDocument(): bool
    {
        return $this->kind() === Type::Document;
    }

    /**
     * The stored `type` wins, like `typeOf()` in ContentTypeCompatibleWithMedia
     * and `classify()` in mediaType.ts; items saved before it existed classify
     * by MIME, then by path.
     */
    private function kind(): ?Type
    {
        return $this->type ?? Type::classify($this->mime_type, $this->path);
    }

    /**
     * Stored pixel width from upload-time metadata, when known.
     */
    public function width(): ?int
    {
        $width = data_get($this->meta, 'width');

        return is_numeric($width) ? (int) $width : null;
    }

    /**
     * Stored pixel height from upload-time metadata, when known.
     */
    public function height(): ?int
    {
        $height = data_get($this->meta, 'height');

        return is_numeric($height) ? (int) $height : null;
    }

    /**
     * User-provided accessibility description for this image, when set.
     */
    public function altText(): ?string
    {
        $alt = data_get($this->meta, 'alt_text');

        if (! is_string($alt)) {
            return null;
        }

        $alt = trim($alt);

        return $alt === '' ? null : $alt;
    }

    /**
     * The alt text truncated to the given platform's cap, or null when no alt
     * text is set or the platform doesn't support it. Single place that applies
     * the per-platform cap so publishers don't each repeat the truncation.
     */
    public function altTextFor(Platform $platform): ?string
    {
        $alt = $this->altText();
        $max = $platform->altTextMaxLength();

        if ($alt === null || $max === null) {
            return null;
        }

        return mb_substr($alt, 0, $max);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $path = data_get($data, 'path', '');
        $mimeType = data_get($data, 'mime_type') ?: Type::mimeTypeFromExtension(Type::extensionOf($path));

        $sourceValue = data_get($data, 'source');
        $source = is_string($sourceValue) ? Source::tryFrom($sourceValue) : null;

        $sourceMeta = data_get($data, 'source_meta');
        $meta = data_get($data, 'meta');
        $type = data_get($data, 'type');
        $size = data_get($data, 'size');

        return new self(
            id: (string) data_get($data, 'id', ''),
            path: $path,
            url: data_get($data, 'url', ''),
            mime_type: $mimeType,
            original_filename: data_get($data, 'original_filename'),
            source: $source,
            source_meta: is_array($sourceMeta) ? $sourceMeta : null,
            meta: is_array($meta) ? $meta : null,
            type: is_string($type) ? Type::tryFrom($type) : null,
            size: is_numeric($size) ? (int) $size : null,
        );
    }
}

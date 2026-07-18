<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\Media\Source;
use App\Enums\Media\Type;
use App\Enums\SocialAccount\Platform;

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
    ) {}

    public function isVideo(): bool
    {
        return Type::classify($this->mime_type, $this->path) === Type::Video;
    }

    public function isImage(): bool
    {
        return Type::classify($this->mime_type, $this->path) === Type::Image;
    }

    public function isDocument(): bool
    {
        return Type::classify($this->mime_type, $this->path) === Type::Document;
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
        $mimeType = data_get($data, 'mime_type');

        if (! $mimeType && $path) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeType = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'mp4' => 'video/mp4',
                'mov' => 'video/quicktime',
                'pdf' => 'application/pdf',
                default => null,
            };
        }

        $sourceValue = data_get($data, 'source');
        $source = is_string($sourceValue) ? Source::tryFrom($sourceValue) : null;

        $sourceMeta = data_get($data, 'source_meta');
        $meta = data_get($data, 'meta');

        return new self(
            id: data_get($data, 'id', ''),
            path: $path,
            url: data_get($data, 'url', ''),
            mime_type: $mimeType,
            original_filename: data_get($data, 'original_filename'),
            source: $source,
            source_meta: is_array($sourceMeta) ? $sourceMeta : null,
            meta: is_array($meta) ? $meta : null,
        );
    }
}

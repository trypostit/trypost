<?php

declare(strict_types=1);

namespace App\Enums\Media;

enum Type: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Imagem',
            self::Video => 'Vídeo',
            self::Document => 'Documento',
        };
    }

    /**
     * Allow-list of MIME types we accept on upload / URL fetch.
     *
     * Video accepts MP4 plus QuickTime/MOV. Modern .mov files (iPhone
     * recordings, screen captures) are ISO BMFF containers — the same
     * format MP4 uses — so social platforms decode them like MP4 even
     * if PHP reports `video/quicktime`. Accepting MOV avoids forcing
     * iPhone users to transcode before uploading.
     *
     * WebM is rejected: X / IG / TikTok / FB / Pinterest / Bluesky /
     * Threads all reject the Matroska + VP8/VP9 stack. Without
     * server-side transcoding, accepting WebM would just produce
     * platform-specific publish failures down the line.
     *
     * Document accepts PDF only — the swipeable LinkedIn document
     * (carousel) format. PPTX/DOCX are also valid LinkedIn documents
     * but are converted server-side by LinkedIn and lose fonts, so we
     * keep the surface to PDF.
     *
     * @return array<int, string>
     */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::Image => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            self::Video => ['video/mp4', 'video/quicktime'],
            self::Document => ['application/pdf'],
        };
    }

    /**
     * Filename extensions that match this type. Mirrors allowedMimeTypes
     * for callers that validate by name instead of MIME.
     *
     * @return array<int, string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::Image => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            self::Video => ['mp4', 'mov'],
            self::Document => ['pdf'],
        };
    }

    public function maxSizeInMb(): int
    {
        return (int) config("trypost.media.max_size_mb.{$this->value}");
    }

    public function maxSizeInBytes(): int
    {
        return $this->maxSizeInMb() * 1024 * 1024;
    }

    public function maxSizeInKb(): int
    {
        return $this->maxSizeInMb() * 1024;
    }

    /**
     * Resolve a Type from a MIME string. Returns null when the MIME is
     * not in any type's allow-list.
     */
    public static function fromMime(string $mime): ?self
    {
        foreach (self::cases() as $type) {
            if (in_array($mime, $type->allowedMimeTypes(), true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Classify media by what it *is* — for "is this an image/video/PDF?" checks,
     * as opposed to fromMime() which is the strict upload allow-list. Any
     * `image/*`, `video/*`, or `application/pdf` MIME maps to its type; when the
     * MIME is missing it falls back to the filename extension so already-stored
     * files still resolve.
     */
    public static function classify(?string $mimeType, ?string $path = null): ?self
    {
        if (filled($mimeType)) {
            return match (true) {
                str_starts_with($mimeType, 'image/') => self::Image,
                str_starts_with($mimeType, 'video/') => self::Video,
                $mimeType === 'application/pdf' => self::Document,
                default => null,
            };
        }

        return self::fromExtension($path ? pathinfo($path, PATHINFO_EXTENSION) : null);
    }

    /**
     * Classify by filename extension. Broader than extensions() (the upload
     * allow-list) so already-stored files in legacy formats still resolve.
     */
    public static function fromExtension(?string $extension): ?self
    {
        $extension = strtolower((string) $extension);

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic', 'heif'], true) => self::Image,
            in_array($extension, ['mp4', 'mov', 'avi', 'wmv', 'webm', 'mkv', 'm4v'], true) => self::Video,
            $extension === 'pdf' => self::Document,
            default => null,
        };
    }

    /**
     * Whether the MIME is an animated GIF — several publishers handle it
     * specially (skipped from optimization, or posted as video).
     */
    public static function isGif(?string $mimeType): bool
    {
        return $mimeType === 'image/gif';
    }

    /**
     * Whether the file is QuickTime/MOV. Bluesky's video lexicon is MP4 only,
     * so the editor and ContentTypeCompatibleWithMedia reject MOV there.
     */
    public static function isMov(?string $mimeType, ?string $path = null): bool
    {
        if ($mimeType === 'video/quicktime') {
            return true;
        }

        return strtolower((string) pathinfo((string) $path, PATHINFO_EXTENSION)) === 'mov';
    }
}

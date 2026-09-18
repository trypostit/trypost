<?php

declare(strict_types=1);

namespace App\Enums\Media;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Mime\MimeTypes;

enum Type: string
{
    case Image = 'image';
    case Video = 'video';
    case Document = 'document';

    private const GIF_MIME = 'image/gif';

    private const MOV_MIME = 'video/quicktime';

    private const PDF_MIME = 'application/pdf';

    /**
     * Allow-list of MIME types we accept on upload / URL fetch.
     *
     * Video accepts MP4 plus QuickTime/MOV. Modern .mov files (iPhone
     * recordings, screen captures) are ISO BMFF containers — the same
     * format MP4 uses — so social platforms decode them like MP4 even
     * if PHP reports `video/quicktime`. Accepting MOV avoids forcing
     * iPhone users to transcode before uploading.
     *
     * WebM is rejected: X / IG / FB / Pinterest / Threads refuse the
     * Matroska + VP8/VP9 stack outright, and only TikTok and Bluesky
     * would transcode it. Without server-side transcoding, accepting
     * WebM would just produce platform-specific publish failures.
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
            self::Image => ['image/jpeg', 'image/png', self::GIF_MIME, 'image/webp'],
            self::Video => ['video/mp4', self::MOV_MIME],
            self::Document => [self::PDF_MIME],
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
        return array_find(self::cases(), fn (self $type) => in_array($mime, $type->allowedMimeTypes(), true));
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
        $mimeType = self::normalizeMime($mimeType);

        return $mimeType === ''
            ? self::fromExtension(self::extensionOf($path))
            : self::owner($mimeType);
    }

    /**
     * Classify by filename extension. Broader than extensions(): any format the
     * MIME registry knows as image/*, video/* or PDF resolves, so legacy files
     * already on disk (heic, mkv, avi, ...) still classify. The registry's order
     * decides: `.pdf` lists `application/pdf` before `image/pdf`, so it is a
     * Document, not an Image.
     */
    public static function fromExtension(?string $extension): ?self
    {
        return collect(self::registeredMimeTypes($extension))->map(self::owner(...))->filter()->first();
    }

    /**
     * The MIME we accept on upload for a filename extension, or null when the
     * extension maps to nothing in the allow-list.
     */
    public static function mimeTypeFromExtension(?string $extension): ?string
    {
        return Arr::first(self::registeredMimeTypes($extension), fn (string $mimeType) => self::fromMime($mimeType) !== null);
    }

    /**
     * Image and video own their MIME family (`image/*`, `video/*`), so the
     * family is the backing value. Document is `application/pdf` alone.
     * A string without a slash is not a MIME and owns nothing.
     */
    private function ownsMime(string $mimeType): bool
    {
        return match ($this) {
            self::Document => $mimeType === self::PDF_MIME,
            default => Str::is("{$this->value}/*", $mimeType),
        };
    }

    private static function owner(string $mimeType): ?self
    {
        return array_find(self::cases(), fn (self $type) => $type->ownsMime($mimeType));
    }

    /**
     * Every MIME the registry lists for an extension — `.mp4` comes back as
     * `application/mp4` first and `video/mp4` second, which is why callers
     * pick from the whole list instead of trusting the first entry.
     *
     * @return array<int, string>
     */
    private static function registeredMimeTypes(?string $extension): array
    {
        return MimeTypes::getDefault()->getMimeTypes(Str::lower((string) $extension));
    }

    /**
     * Whether the MIME is an animated GIF — several publishers handle it
     * specially (skipped from optimization, or posted as video).
     */
    public static function isGif(?string $mimeType): bool
    {
        return self::normalizeMime($mimeType) === self::GIF_MIME;
    }

    public static function isMov(?string $mimeType, ?string $path = null): bool
    {
        return self::normalizeMime($mimeType) === self::MOV_MIME || self::extensionOf($path) === 'mov';
    }

    /**
     * Lower-cased extension of a filename, storage key or URL. Mirrors `pathOf`
     * in mediaType.ts: only an absolute URL is parsed (to drop `?query` and
     * `#hash`); a bare filename is taken as-is, so `Photo #3.jpg` keeps its
     * extension.
     */
    public static function extensionOf(?string $path): string
    {
        $path = (string) $path;

        if (Str::contains($path, '://')) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        return Str::lower(File::extension($path));
    }

    /**
     * Lower-cased `type/subtype` with any `; codecs=...` parameter dropped, so
     * comparisons are exact. Mirrors `normalizeMime` in mediaType.ts.
     */
    private static function normalizeMime(?string $mimeType): string
    {
        return Str::of((string) $mimeType)->before(';')->trim()->lower()->value();
    }
}

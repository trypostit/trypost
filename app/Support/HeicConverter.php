<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Imagick;
use Intervention\Image\Drivers\Imagick\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * HEIC/HEIF is what iPhones shoot. No network accepts it, so it is decoded
 * with Imagick on upload and stored as an upright JPEG. Without Imagick built
 * against libheif the format is not accepted at all.
 */
final class HeicConverter
{
    /**
     * @var array<int, string>
     */
    public const MIME_TYPES = ['image/heic', 'image/heif'];

    /**
     * @var array<int, string>
     */
    public const EXTENSIONS = ['heic', 'heif'];

    /**
     * Multi-image HEIF containers (bursts, Live Photos). They are refused
     * rather than flattened to one frame, which would drop the rest silently.
     *
     * @var array<int, string>
     */
    public const SEQUENCE_MIME_TYPES = ['image/heic-sequence', 'image/heif-sequence'];

    private const int UNBOUNDED_SECONDS = 31536000000;

    private static ?bool $available = null;

    public static function available(): bool
    {
        if (! config('trypost.media.heic_conversion')) {
            return false;
        }

        return self::$available ??= extension_loaded('imagick') && Imagick::queryFormats('HEIC') !== [];
    }

    public static function flush(): void
    {
        self::$available = null;
    }

    public static function isHeicMime(?string $mimeType): bool
    {
        return in_array(Str::of((string) $mimeType)->before(';')->trim()->lower()->value(), self::MIME_TYPES, true);
    }

    public static function isSequenceMime(?string $mimeType): bool
    {
        return in_array(Str::of((string) $mimeType)->before(';')->trim()->lower()->value(), self::SEQUENCE_MIME_TYPES, true);
    }

    public static function isHeicExtension(?string $extension): bool
    {
        return in_array(Str::lower((string) $extension), self::EXTENSIONS, true);
    }

    /**
     * Whether a file announced by MIME and/or extension is HEIC.
     */
    public static function isHeic(?string $mimeType, ?string $extension = null): bool
    {
        return self::isHeicMime($mimeType) || self::isHeicExtension($extension);
    }

    /**
     * `photo.HEIC` becomes `photo.jpg`; any other name is returned as is.
     */
    public static function convertedFilename(string $filename, ?string $mimeType): string
    {
        return self::isHeicMime($mimeType) ? pathinfo($filename, PATHINFO_FILENAME).'.jpg' : $filename;
    }

    /**
     * Imagick resource type => `trypost.media.heic_limits` key and the unit
     * that turns the configured value into what Imagick takes.
     *
     * @var array<int, array{0: string, 1: int}>
     */
    private const LIMITS = [
        Imagick::RESOURCETYPE_MEMORY => ['memory_mb', 1048576],
        Imagick::RESOURCETYPE_MAP => ['map_mb', 1048576],
        Imagick::RESOURCETYPE_DISK => ['disk_mb', 1048576],
        Imagick::RESOURCETYPE_AREA => ['area_mb', 1048576],
        Imagick::RESOURCETYPE_WIDTH => ['width_px', 1],
        Imagick::RESOURCETYPE_HEIGHT => ['height_px', 1],
        Imagick::RESOURCETYPE_TIME => ['time_seconds', 1],
    ];

    /**
     * JPEG q100 bytes with the EXIF orientation applied to the pixels. The
     * decode runs under the limits of `trypost.media.heic_limits` and the
     * limits Imagick had before are put back afterwards, so the rest of the
     * process (MediaOptimizer) is not held to them.
     */
    public static function toJpeg(string $filePath): string
    {
        $previous = [];

        foreach (self::LIMITS as $resource => [$key, $unit]) {
            $previous[$resource] = Imagick::getResourceLimit($resource);
            Imagick::setResourceLimit($resource, (int) config("trypost.media.heic_limits.{$key}") * $unit);
        }

        try {
            return (string) (new ImageManager(new Driver))->decodePath($filePath)->orient()->encode(new JpegEncoder(quality: 100));
        } finally {
            foreach ($previous as $resource => $limit) {
                Imagick::setResourceLimit($resource, self::restorable($resource, $limit));
            }
        }
    }

    /**
     * Imagick reports "unlimited" as 2^63; handing that back as the time limit
     * trips `TimeLimitExceeded` on the next read, so time falls back to a
     * thousand years, which is as good as none.
     */
    private static function restorable(int $resource, float $limit): int
    {
        if ($limit < PHP_INT_MAX) {
            return (int) $limit;
        }

        return $resource === Imagick::RESOURCETYPE_TIME ? self::UNBOUNDED_SECONDS : PHP_INT_MAX;
    }
}

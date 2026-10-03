<?php

declare(strict_types=1);

namespace App\Support\Media;

/**
 * Pixel size of an image as people see it: a JPEG whose EXIF orientation is
 * 5–8 (a phone photo taken sideways) is stored rotated, so its raw width and
 * height are swapped back, like browsers do when they render it.
 */
final class ImageDimensions
{
    private const ROTATED_ORIENTATIONS = [5, 6, 7, 8];

    /**
     * @return array{width: int, height: int}|null
     */
    public static function fromBytes(string $bytes): ?array
    {
        $info = @getimagesizefromstring($bytes);

        if (! is_array($info) || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }

        return in_array(self::orientation($bytes, (int) $info[2]), self::ROTATED_ORIENTATIONS, true)
            ? ['width' => $info[1], 'height' => $info[0]]
            : ['width' => $info[0], 'height' => $info[1]];
    }

    private static function orientation(string $bytes, int $imageType): ?int
    {
        if ($imageType !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return null;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);

        try {
            $exif = @exif_read_data($stream);
        } finally {
            fclose($stream);
        }

        return is_array($exif) && is_numeric($exif['Orientation'] ?? null) ? (int) $exif['Orientation'] : null;
    }
}

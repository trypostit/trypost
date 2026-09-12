<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Generator;

/**
 * Reads a video's duration from the `moov > mvhd` atom of an MP4 / MOV file.
 * Walks atom headers only, so a file whose `moov` sits after a large `mdat`
 * costs a few small reads rather than a full download.
 */
final class VideoDurationProbe
{
    private const HEADER_BYTES = 16;

    private const MVHD_BYTES = 32;

    public static function fromFile(string $path): ?float
    {
        $size = @filesize($path);
        $handle = @fopen($path, 'rb');

        if ($size === false || $handle === false) {
            return null;
        }

        try {
            return self::fromReader(static function (int $offset, int $length) use ($handle): string {
                fseek($handle, $offset);

                return (string) fread($handle, $length);
            }, $size);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  Closure(int, int): string  $read  Returns up to `$length` bytes starting at `$offset`.
     */
    public static function fromReader(Closure $read, int $totalSize): ?float
    {
        foreach (self::atoms($read, 0, $totalSize) as [$type, $start, $length]) {
            if ($type !== 'moov') {
                continue;
            }

            foreach (self::atoms($read, $start, $start + $length) as [$child, $childStart, $childLength]) {
                if ($child === 'mvhd') {
                    return self::durationFromMvhd($read($childStart, min($childLength, self::MVHD_BYTES)));
                }
            }

            return null;
        }

        return null;
    }

    /**
     * @param  Closure(int, int): string  $read
     * @return Generator<int, array{0: string, 1: int, 2: int}> [type, payload offset, payload length]
     */
    private static function atoms(Closure $read, int $offset, int $end): Generator
    {
        while ($offset + 8 <= $end) {
            $header = $read($offset, min(self::HEADER_BYTES, $end - $offset));

            if (strlen($header) < 8) {
                return;
            }

            $size = unpack('N', $header)[1];
            $headerLength = 8;

            if ($size === 1) {
                if (strlen($header) < 16) {
                    return;
                }

                $size = unpack('J', $header, 8)[1];
                $headerLength = 16;
            } elseif ($size === 0) {
                $size = $end - $offset;
            }

            if ($size < $headerLength) {
                return;
            }

            yield [substr($header, 4, 4), $offset + $headerLength, $size - $headerLength];

            $offset += $size;
        }
    }

    private static function durationFromMvhd(string $mvhd): ?float
    {
        $isVersion1 = ord($mvhd[0] ?? "\0") === 1;
        $timescaleOffset = $isVersion1 ? 20 : 12;

        if (strlen($mvhd) < $timescaleOffset + ($isVersion1 ? 12 : 8)) {
            return null;
        }

        $timescale = unpack('N', $mvhd, $timescaleOffset)[1];
        $duration = unpack($isVersion1 ? 'J' : 'N', $mvhd, $timescaleOffset + 4)[1];

        return $timescale > 0 && $duration > 0 ? $duration / $timescale : null;
    }
}

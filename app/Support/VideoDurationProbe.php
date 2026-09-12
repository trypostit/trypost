<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Generator;

/**
 * Reads a video's duration from the `moov > mvhd` atom of an MP4 / MOV file.
 * Only atom headers are read, so a file whose `moov` sits after a large `mdat`
 * costs a handful of small reads rather than a full download.
 */
final class VideoDurationProbe
{
    private const ATOM_HEADER_BYTES = 8;

    private const ATOM_LARGE_HEADER_BYTES = 16;

    private const MVHD_V0_BYTES = 20;

    private const MVHD_V1_BYTES = 32;

    /**
     * @param  Closure(int, int): string  $reader  Returns up to `$length` bytes starting at `$offset`.
     */
    private function __construct(
        private readonly Closure $reader,
        private readonly int $size,
    ) {}

    public static function fromFile(string $path): ?float
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            $read = static function (int $offset, int $length) use ($handle): string {
                fseek($handle, $offset);

                return (string) fread($handle, $length);
            };

            return (new self($read, (int) (fstat($handle)['size'] ?? 0)))->duration();
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  Closure(int, int): string  $read  Returns up to `$length` bytes starting at `$offset`.
     */
    public static function fromReader(Closure $read, int $totalSize): ?float
    {
        return (new self($read, $totalSize))->duration();
    }

    private function duration(): ?float
    {
        $moov = $this->child('moov', 0, $this->size);
        $mvhd = $moov === null ? null : $this->child('mvhd', ...$moov);

        if ($mvhd === null) {
            return null;
        }

        [$start, $end] = $mvhd;

        return $this->durationFromMvhd($this->read($start, min($end - $start, self::MVHD_V1_BYTES)));
    }

    /**
     * Payload bounds of the first `$type` atom between `$from` and `$to`.
     *
     * @return array{int, int}|null
     */
    private function child(string $type, int $from, int $to): ?array
    {
        foreach ($this->atoms($from, $to) as $atomType => $bounds) {
            if ($atomType === $type) {
                return $bounds;
            }
        }

        return null;
    }

    /**
     * Walks the atoms laid out between `$from` and `$to`, yielding each type
     * with its payload bounds. An atom claiming more bytes than remain is
     * clamped, and a header that cannot be read ends the walk.
     *
     * @return Generator<string, array{int, int}>
     */
    private function atoms(int $from, int $to): Generator
    {
        $offset = $from;

        while ($offset + self::ATOM_HEADER_BYTES <= $to) {
            $header = $this->read($offset, min(self::ATOM_LARGE_HEADER_BYTES, $to - $offset));

            if (strlen($header) < self::ATOM_HEADER_BYTES) {
                return;
            }

            ['size' => $size, 'type' => $type] = unpack('Nsize/a4type', $header);
            $headerBytes = self::ATOM_HEADER_BYTES;

            if ($size === 1 && strlen($header) === self::ATOM_LARGE_HEADER_BYTES) {
                $size = unpack('J', $header, self::ATOM_HEADER_BYTES)[1];
                $headerBytes = self::ATOM_LARGE_HEADER_BYTES;
            } elseif ($size === 0) {
                $size = $to - $offset;
            }

            if ($size < $headerBytes) {
                return;
            }

            $size = min($size, $to - $offset);

            yield $type => [$offset + $headerBytes, $offset + $size];

            $offset += $size;
        }
    }

    /**
     * `mvhd` starts with a version byte; version 1 widens the creation and
     * modification times to 64 bits, which pushes `timescale` and `duration`
     * from offsets 12 / 16 to 20 / 24 and makes `duration` 64-bit as well.
     */
    private function durationFromMvhd(string $mvhd): ?float
    {
        $isVersion1 = ord($mvhd[0] ?? "\0") === 1;

        if (strlen($mvhd) < ($isVersion1 ? self::MVHD_V1_BYTES : self::MVHD_V0_BYTES)) {
            return null;
        }

        ['timescale' => $timescale, 'duration' => $duration] = unpack(
            $isVersion1 ? 'x20/Ntimescale/Jduration' : 'x12/Ntimescale/Nduration',
            $mvhd,
        );

        return $timescale > 0 && $duration > 0 ? $duration / $timescale : null;
    }

    private function read(int $offset, int $length): string
    {
        return ($this->reader)($offset, $length);
    }
}

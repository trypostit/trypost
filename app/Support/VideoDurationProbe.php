<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Generator;
use Illuminate\Support\Str;

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

    private const MVHD_UNKNOWN_DURATION = 0xFFFFFFFF;

    /**
     * A real file has a handful of atoms per level (`ftyp`, `moov`, `mdat`, a
     * few `trak`s). Each header is one read — a ranged GET on object storage —
     * so an upload padded with thousands of tiny atoms must not turn into
     * thousands of requests. Past this many, the duration is simply unknown.
     */
    private const MAX_ATOMS_PER_LEVEL = 64;

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
            return self::fromReader(
                static fn (int $offset, int $length): string => fseek($handle, $offset) === 0
                    ? (string) fread($handle, $length)
                    : '',
                (int) data_get(fstat($handle), 'size', 0),
            );
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  Closure(int, int): string  $read  Returns up to `$length` bytes starting at `$offset`.
     */
    public static function fromReader(Closure $read, int $totalSize): ?float
    {
        return (new self($read, max(0, $totalSize)))->duration();
    }

    /**
     * Writes a usable duration into `$meta`; nothing, zero, negative or
     * non-finite (INF cannot be JSON-encoded) leaves it untouched.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function mergeInto(array $meta, ?float $duration): array
    {
        return $duration === null || ! is_finite($duration) || $duration <= 0
            ? $meta
            : [...$meta, 'duration' => round($duration, 2)];
    }

    /**
     * `moov` holds `mvhd`, and `mvhd` holds the duration. Each step returns
     * null when its atom is missing, and the next step passes that through.
     */
    private function duration(): ?float
    {
        return $this->seconds($this->payload('mvhd', $this->payload('moov', [0, $this->size])));
    }

    /**
     * Payload bounds of the first `$type` atom laid out within `$within`.
     *
     * @param  array{int, int}|null  $within
     * @return array{int, int}|null
     */
    private function payload(string $type, ?array $within): ?array
    {
        if ($within === null) {
            return null;
        }

        // A plain loop, not Arr::first(): that would drain the generator first,
        // reading every sibling header (one ranged GET each on object storage).
        foreach ($this->atoms(...$within) as $atomType => $bounds) {
            if ($atomType === $type) {
                return $bounds;
            }
        }

        return null;
    }

    /**
     * Walks the atoms laid out between `$from` and `$to`, yielding each type
     * with its payload bounds. A header that cannot be read, or one atom too
     * many, ends the walk.
     *
     * @return Generator<string, array{int, int}>
     */
    private function atoms(int $from, int $to): Generator
    {
        $offset = $from;
        $remaining = self::MAX_ATOMS_PER_LEVEL;

        while ($remaining-- > 0 && $offset + self::ATOM_HEADER_BYTES <= $to && ($header = $this->header($offset, $to)) !== null) {
            [$size, $type, $headerBytes] = $header;

            yield $type => [$offset + $headerBytes, $offset + $size];

            $offset += $size;
        }
    }

    /**
     * Reads one atom header. A size of 1 means a 64-bit "largesize" follows the
     * type; a size of 0 means the atom runs to the end of its parent. Either way
     * the size is clamped to the bytes that remain.
     *
     * @return array{int, string, int}|null
     */
    private function header(int $offset, int $to): ?array
    {
        $available = $to - $offset;
        $bytes = $this->read($offset, min(self::ATOM_LARGE_HEADER_BYTES, $available));

        if (strlen($bytes) < self::ATOM_HEADER_BYTES) {
            return null;
        }

        ['size' => $size, 'type' => $type] = unpack('Nsize/a4type', $bytes);
        $headerBytes = self::ATOM_HEADER_BYTES;

        if ($size === 1 && strlen($bytes) === self::ATOM_LARGE_HEADER_BYTES) {
            [1 => $size] = unpack('J', $bytes, self::ATOM_HEADER_BYTES);
            $headerBytes = self::ATOM_LARGE_HEADER_BYTES;
        } elseif ($size === 0) {
            $size = $available;
        }

        $size = min($size, $available);

        return $size < $headerBytes ? null : [$size, $type, $headerBytes];
    }

    /**
     * `mvhd` starts with a version byte; version 1 widens the creation and
     * modification times to 64 bits, which pushes `timescale` and `duration`
     * from offsets 12 / 16 to 20 / 24 and makes `duration` 64-bit as well.
     *
     * A duration of all 1s means "unknown" per ISO 14496-12. In version 1 that
     * reads back as -1 and fails the `> 0` check on its own; version 0 needs
     * the explicit sentinel, or a live capture would report ~49 days.
     *
     * @param  array{int, int}|null  $bounds
     */
    private function seconds(?array $bounds): ?float
    {
        if ($bounds === null) {
            return null;
        }

        [$start, $end] = $bounds;

        if ($end - $start < self::MVHD_V0_BYTES) {
            return null;
        }

        $mvhd = $this->read($start, min($end - $start, self::MVHD_V1_BYTES));

        [$needed, $format] = Str::startsWith($mvhd, "\x01")
            ? [self::MVHD_V1_BYTES, 'x20/Ntimescale/Jduration']
            : [self::MVHD_V0_BYTES, 'x12/Ntimescale/Nduration'];

        if (strlen($mvhd) < $needed) {
            return null;
        }

        ['timescale' => $timescale, 'duration' => $duration] = unpack($format, $mvhd);

        return $timescale > 0 && $duration > 0 && $duration !== self::MVHD_UNKNOWN_DURATION
            ? $duration / $timescale
            : null;
    }

    private function read(int $offset, int $length): string
    {
        return ($this->reader)($offset, $length);
    }
}

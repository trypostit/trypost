<?php

declare(strict_types=1);

use App\Support\VideoDurationProbe;

function probeAtom(string $type, string $payload, bool $largesize = false): string
{
    if ($largesize) {
        return pack('N', 1).$type.pack('J', 16 + strlen($payload)).$payload;
    }

    return pack('N', 8 + strlen($payload)).$type.$payload;
}

function probeMvhd(int $timescale, int $duration, int $version = 0): string
{
    $payload = $version === 1
        ? "\x01\0\0\0".pack('J', 0).pack('J', 0).pack('N', $timescale).pack('J', $duration)
        : "\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', $timescale).pack('N', $duration);

    return probeAtom('mvhd', $payload.str_repeat("\0", 80));
}

function probeMp4(string ...$atoms): string
{
    return implode('', $atoms);
}

function probeReader(string $bytes): Closure
{
    return fn (int $offset, int $length): string => substr($bytes, $offset, $length);
}

test('reads the duration from the real fixture whose moov follows mdat', function () {
    expect(VideoDurationProbe::fromFile(base_path('tests/fixtures/sample.mp4')))->toBe(1.0);
});

test('reads real ffmpeg output: a QuickTime .mov and a version 1 mvhd', function () {
    // Both values were cross-checked against `ffprobe -show_entries format=duration`.
    expect(VideoDurationProbe::fromFile(base_path('tests/fixtures/sample.mov')))->toBe(2.5)
        ->and(VideoDurationProbe::fromFile(base_path('tests/fixtures/sample-mvhd-v1.mp4')))->toBe(2_400_000.0);
});

test('treats the all-ones "unknown" duration as missing in both mvhd versions', function () {
    $v0 = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeMvhd(1000, 0xFFFFFFFF)));
    $v1 = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeMvhd(1000, -1, version: 1)));

    expect(VideoDurationProbe::fromReader(probeReader($v0), strlen($v0)))->toBeNull()
        ->and(VideoDurationProbe::fromReader(probeReader($v1), strlen($v1)))->toBeNull();
});

test('reads a moov-first file', function () {
    $bytes = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeMvhd(600, 45_000)), probeAtom('mdat', str_repeat('x', 100)));

    expect(VideoDurationProbe::fromReader(probeReader($bytes), strlen($bytes)))->toBe(75.0);
});

test('reads a version 1 mvhd with 64-bit duration', function () {
    $bytes = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeMvhd(1000, 7_215_000, version: 1)));

    expect(VideoDurationProbe::fromReader(probeReader($bytes), strlen($bytes)))->toBe(7215.0);
});

test('skips a largesize mdat and a trailing size-zero atom', function () {
    $bytes = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('mdat', str_repeat('x', 64), largesize: true), pack('N', 0).'moov'.probeMvhd(30, 90));

    expect(VideoDurationProbe::fromReader(probeReader($bytes), strlen($bytes)))->toBe(3.0);
});

test('returns null when there is no mvhd, the duration is zero, or the bytes are not a video', function () {
    $noMvhd = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeAtom('udta', 'x')));
    $fragmented = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeMvhd(1000, 0)));

    expect(VideoDurationProbe::fromReader(probeReader($noMvhd), strlen($noMvhd)))->toBeNull()
        ->and(VideoDurationProbe::fromReader(probeReader($fragmented), strlen($fragmented)))->toBeNull()
        ->and(VideoDurationProbe::fromReader(probeReader('not a video at all'), 18))->toBeNull()
        ->and(VideoDurationProbe::fromReader(probeReader(''), 0))->toBeNull();
});

test('stops on a truncated or malformed atom header instead of looping', function () {
    $truncated = probeAtom('ftyp', 'isom').pack('N', 5000).'moov';
    $zeroSized = probeAtom('ftyp', 'isom').pack('N', 4).'moov'.probeMvhd(1, 1);

    expect(VideoDurationProbe::fromReader(probeReader($truncated), strlen($truncated)))->toBeNull()
        ->and(VideoDurationProbe::fromReader(probeReader($zeroSized), strlen($zeroSized)))->toBeNull();
});

test('reads only atom headers, never the mdat payload', function () {
    $bytes = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('mdat', str_repeat('x', 1_000_000)), probeAtom('moov', probeMvhd(1, 12)));
    $bytesRead = 0;

    $duration = VideoDurationProbe::fromReader(function (int $offset, int $length) use ($bytes, &$bytesRead): string {
        $bytesRead += $length;

        return substr($bytes, $offset, $length);
    }, strlen($bytes));

    expect($duration)->toBe(12.0)
        ->and($bytesRead)->toBeLessThan(200);
});

test('stops reading once mvhd is found and takes the first one, not the last', function () {
    // Two mvhd atoms: the first is the real one; the trailing `trak`s and the second mvhd must never be read.
    $moov = probeAtom('moov', probeMvhd(1, 12).probeAtom('trak', str_repeat('t', 64)).probeAtom('trak', str_repeat('t', 64)).probeMvhd(1, 99));
    $bytes = probeMp4(probeAtom('ftyp', 'isom'), $moov);
    $offsets = [];

    $duration = VideoDurationProbe::fromReader(function (int $offset, int $length) use ($bytes, &$offsets): string {
        $offsets[] = $offset;

        return substr($bytes, $offset, $length);
    }, strlen($bytes));

    // ftyp header, moov header, mvhd header, mvhd payload — and nothing past the first mvhd.
    expect($duration)->toBe(12.0)
        ->and($offsets)->toHaveCount(4)
        ->and(max($offsets))->toBeLessThan(strpos($bytes, 'trak'));
});

test('gives up after a bounded number of atoms so a padded upload cannot fan out into thousands of reads', function () {
    // 10k empty `free` atoms in front of moov: every header would be one ranged GET on object storage.
    $padding = str_repeat(probeAtom('free', ''), 10_000);
    $bytes = probeMp4(probeAtom('ftyp', 'isom'), $padding, probeAtom('moov', probeMvhd(1, 12)));
    $reads = 0;

    $duration = VideoDurationProbe::fromReader(function (int $offset, int $length) use ($bytes, &$reads): string {
        $reads++;

        return substr($bytes, $offset, $length);
    }, strlen($bytes));

    expect($duration)->toBeNull()
        ->and($reads)->toBeLessThanOrEqual(64);
});

test('returns null for an unreadable path', function () {
    expect(VideoDurationProbe::fromFile('/nonexistent/clip.mp4'))->toBeNull();
});

test('merges a positive duration into meta and leaves the rest untouched', function () {
    expect(VideoDurationProbe::mergeInto(['width' => 1920], 1.239))
        ->toBe(['width' => 1920, 'duration' => 1.24])
        ->and(VideoDurationProbe::mergeInto(['width' => 1920], null))->toBe(['width' => 1920])
        ->and(VideoDurationProbe::mergeInto(['width' => 1920], 0.0))->toBe(['width' => 1920])
        ->and(VideoDurationProbe::mergeInto(['width' => 1920], -3.0))->toBe(['width' => 1920])
        ->and(VideoDurationProbe::mergeInto(['width' => 1920], INF))->toBe(['width' => 1920])
        ->and(VideoDurationProbe::mergeInto(['width' => 1920], NAN))->toBe(['width' => 1920]);
});

test('returns null for a zero timescale or an mvhd payload shorter than its version needs', function () {
    $zeroTimescale = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeMvhd(0, 1000)));
    // Version 1 needs 32 bytes; this payload declares v1 but carries only the v0 length.
    $shortV1 = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeAtom('mvhd', "\x01".str_repeat("\0", 19))));
    $shortV0 = probeMp4(probeAtom('ftyp', 'isom'), probeAtom('moov', probeAtom('mvhd', str_repeat("\0", 8))));

    expect(VideoDurationProbe::fromReader(probeReader($zeroTimescale), strlen($zeroTimescale)))->toBeNull()
        ->and(VideoDurationProbe::fromReader(probeReader($shortV1), strlen($shortV1)))->toBeNull()
        ->and(VideoDurationProbe::fromReader(probeReader($shortV0), strlen($shortV0)))->toBeNull();
});

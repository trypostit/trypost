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
    expect(VideoDurationProbe::fromFile(base_path('tests/Fixtures/sample.mp4')))->toBe(1.0);
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

test('returns null for an unreadable path', function () {
    expect(VideoDurationProbe::fromFile('/nonexistent/clip.mp4'))->toBeNull();
});

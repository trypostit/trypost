<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * @return array<string, array<int, array<string, string>>>
 */
function forMediaFixtures(): array
{
    $image = ['id' => 'image', 'path' => 'media/photo.jpg', 'url' => 'https://cdn.test/photo.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg'];
    $video = ['id' => 'video', 'path' => 'media/clip.mp4', 'url' => 'https://cdn.test/clip.mp4', 'type' => 'video', 'mime_type' => 'video/mp4'];
    $untypedVideo = ['id' => 'untyped', 'path' => 'media/clip.mov', 'url' => 'https://cdn.test/clip.mov', 'mime_type' => 'video/quicktime'];
    $document = ['id' => 'document', 'path' => 'media/deck.pdf', 'url' => 'https://cdn.test/deck.pdf', 'type' => 'document', 'mime_type' => 'application/pdf'];

    return [
        'none' => [],
        'one image' => [$image],
        'two images' => [$image, $image],
        'one video' => [$video],
        'untyped video' => [$untypedVideo],
        'image and video' => [$image, $video],
        'document' => [$document],
    ];
}

test('pinterest takes a video pin for any video, a carousel for several images and a pin otherwise', function (string $media, ContentType $expected) {
    expect(ContentType::forMedia(Platform::Pinterest, forMediaFixtures()[$media]))->toBe($expected);
})->with([
    ['none', ContentType::PinterestPin],
    ['one image', ContentType::PinterestPin],
    ['two images', ContentType::PinterestCarousel],
    ['one video', ContentType::PinterestVideoPin],
    ['untyped video', ContentType::PinterestVideoPin],
    ['image and video', ContentType::PinterestVideoPin],
]);

test('tiktok takes photos for images only and a video otherwise', function (string $media, ContentType $expected) {
    expect(ContentType::forMedia(Platform::TikTok, forMediaFixtures()[$media]))->toBe($expected);
})->with([
    ['none', ContentType::TikTokVideo],
    ['one image', ContentType::TikTokPhoto],
    ['two images', ContentType::TikTokPhoto],
    ['one video', ContentType::TikTokVideo],
    ['untyped video', ContentType::TikTokVideo],
    ['image and video', ContentType::TikTokVideo],
]);

test('every other network takes its default type whatever the media', function (Platform $platform) {
    foreach (forMediaFixtures() as $media) {
        expect(ContentType::forMedia($platform, $media))->toBe(ContentType::defaultFor($platform));
    }

    expect(ContentType::derivesFromMedia($platform))->toBeFalse();
})->with(fn () => collect(Platform::cases())
    ->reject(fn (Platform $platform) => in_array($platform, [Platform::Pinterest, Platform::TikTok], true))
    ->values()
    ->all());

test('only pinterest and tiktok derive their type from the media', function () {
    expect(ContentType::derivesFromMedia(Platform::Pinterest))->toBeTrue()
        ->and(ContentType::derivesFromMedia(Platform::TikTok))->toBeTrue();
});

test('the composer picks the same type as forMedia for every network and media mix', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $cases = [];
    foreach (Platform::cases() as $platform) {
        foreach (forMediaFixtures() as $name => $media) {
            $cases["{$platform->value}|{$name}"] = ['platform' => $platform->value, 'media' => $media];
        }
    }

    $input = tempnam(sys_get_temp_dir(), 'derived-content-type');
    file_put_contents($input, json_encode($cases, JSON_THROW_ON_ERROR));

    try {
        $process = (new Process([
            $node,
            base_path('tests/fixtures/derived-content-type-harness.ts'),
            resource_path('js'),
            $input,
        ]))->mustRun();
    } finally {
        unlink($input);
    }

    $composer = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $server = collect($cases)
        ->map(fn (array $case): string => ContentType::forMedia(Platform::from($case['platform']), $case['media'])->value)
        ->all();

    expect($composer)->toBe($server);
});

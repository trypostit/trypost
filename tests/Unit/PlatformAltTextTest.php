<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;

test('altTextMaxLength returns the documented cap for supporting platforms', function () {
    expect(Platform::Bluesky->altTextMaxLength())->toBe(2000)
        ->and(Platform::X->altTextMaxLength())->toBe(1000)
        ->and(Platform::Mastodon->altTextMaxLength())->toBe(1500)
        ->and(Platform::LinkedIn->altTextMaxLength())->toBe(4086)
        ->and(Platform::LinkedInPage->altTextMaxLength())->toBe(4086)
        ->and(Platform::Instagram->altTextMaxLength())->toBe(1000)
        ->and(Platform::InstagramFacebook->altTextMaxLength())->toBe(1000)
        ->and(Platform::Pinterest->altTextMaxLength())->toBe(500)
        ->and(Platform::Discord->altTextMaxLength())->toBe(1024)
        ->and(Platform::Facebook->altTextMaxLength())->toBe(1000)
        ->and(Platform::Threads->altTextMaxLength())->toBe(1000);
});

test('altTextMaxLength is null for platforms without alt-text support', function () {
    expect(Platform::TikTok->altTextMaxLength())->toBeNull()
        ->and(Platform::Telegram->altTextMaxLength())->toBeNull()
        ->and(Platform::YouTube->altTextMaxLength())->toBeNull();
});

test('supportsImageAltText mirrors altTextMaxLength', function () {
    expect(Platform::Bluesky->supportsImageAltText())->toBeTrue()
        ->and(Platform::LinkedInPage->supportsImageAltText())->toBeTrue()
        ->and(Platform::InstagramFacebook->supportsImageAltText())->toBeTrue()
        ->and(Platform::TikTok->supportsImageAltText())->toBeFalse();
});

test('altTextMaxLength is defined for every platform so a new case cannot slip through', function () {
    foreach (Platform::cases() as $platform) {
        $max = $platform->altTextMaxLength();

        expect($max === null || (is_int($max) && $max > 0))->toBeTrue();
    }
});

test('only bluesky, mastodon, threads and discord take alt text on a video, each also taking it on an image', function (Platform $platform) {
    $expected = in_array($platform, [Platform::Bluesky, Platform::Mastodon, Platform::Threads, Platform::Discord], true);

    expect($platform->supportsVideoAltText())->toBe($expected);

    if ($expected) {
        expect($platform->supportsImageAltText())->toBeTrue();
    }
})->with(Platform::cases());

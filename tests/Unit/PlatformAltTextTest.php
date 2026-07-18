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

test('supportsAltText mirrors altTextMaxLength', function () {
    expect(Platform::Bluesky->supportsAltText())->toBeTrue()
        ->and(Platform::LinkedInPage->supportsAltText())->toBeTrue()
        ->and(Platform::InstagramFacebook->supportsAltText())->toBeTrue()
        ->and(Platform::TikTok->supportsAltText())->toBeFalse();
});

test('altTextMaxLength is defined for every platform so a new case cannot slip through', function () {
    foreach (Platform::cases() as $platform) {
        $max = $platform->altTextMaxLength();

        expect($max === null || (is_int($max) && $max > 0))->toBeTrue();
    }
});

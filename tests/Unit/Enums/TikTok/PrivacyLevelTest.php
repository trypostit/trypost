<?php

declare(strict_types=1);

use App\Enums\TikTok\PrivacyLevel;

test('tiktok privacy level matches the content posting api values', function () {
    expect(PrivacyLevel::values())->toBe([
        'PUBLIC_TO_EVERYONE',
        'MUTUAL_FOLLOW_FRIENDS',
        'FOLLOWER_OF_CREATOR',
        'SELF_ONLY',
    ]);
});

test('the typescript privacy level const matches the php enum', function () {
    $source = file_get_contents(resource_path('js/types/tiktok-privacy.ts'));

    preg_match('/export const TikTokPrivacyLevel = \{([^}]+)\}/s', $source, $block);

    expect($block[1] ?? null)->not->toBeNull();

    preg_match_all("/'([A-Z_]+)'/", $block[1], $matches);

    expect($matches[1])->toBe(PrivacyLevel::values());
});

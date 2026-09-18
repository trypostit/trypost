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

test('the typescript privacy level const contains every php value', function (string $value) {
    expect(file_get_contents(resource_path('js/types/tiktok-privacy.ts')))->toContain("'{$value}'");
})->with(PrivacyLevel::values());

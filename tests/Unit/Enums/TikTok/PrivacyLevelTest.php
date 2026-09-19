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

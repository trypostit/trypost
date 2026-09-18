<?php

declare(strict_types=1);

use App\Enums\TikTok\PrivacyLevel;

test('tiktok privacy level matches the content posting api values', function () {
    expect(PrivacyLevel::values())->toBe([
        PrivacyLevel::PublicToEveryone->value,
        PrivacyLevel::MutualFollowFriends->value,
        PrivacyLevel::FollowerOfCreator->value,
        PrivacyLevel::SelfOnly->value,
    ])->and(PrivacyLevel::values())->toBe([
        'PUBLIC_TO_EVERYONE',
        'MUTUAL_FOLLOW_FRIENDS',
        'FOLLOWER_OF_CREATOR',
        'SELF_ONLY',
    ]);
});

test('known values keep recognized options in order and drop unknowns', function () {
    expect(PrivacyLevel::knownValues([
        PrivacyLevel::PublicToEveryone->value,
        'EVERYONE',
        PrivacyLevel::SelfOnly->value,
        '',
        PrivacyLevel::FollowerOfCreator->value,
    ]))->toBe([
        PrivacyLevel::PublicToEveryone->value,
        PrivacyLevel::SelfOnly->value,
        PrivacyLevel::FollowerOfCreator->value,
    ]);
});

test('only self only forbids branded content', function (PrivacyLevel $level, bool $allowed) {
    expect($level->allowsBrandedContent())->toBe($allowed);
})->with([
    'public' => [PrivacyLevel::PublicToEveryone, true],
    'friends' => [PrivacyLevel::MutualFollowFriends, true],
    'followers' => [PrivacyLevel::FollowerOfCreator, true],
    'private' => [PrivacyLevel::SelfOnly, false],
]);

test('the typescript privacy level const matches the php enum', function () {
    $source = file_get_contents(resource_path('js/types/tiktok-privacy.ts'));

    preg_match('/export const TikTokPrivacyLevel = \{([^}]+)\}/s', $source, $block);

    expect($block[1] ?? null)->not->toBeNull();

    preg_match_all("/'([A-Z_]+)'/", $block[1], $matches);

    expect($matches[1])->toBe(PrivacyLevel::values());
});

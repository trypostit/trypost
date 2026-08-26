<?php

declare(strict_types=1);

use App\Support\Social\InstagramCollaborators;

test('usernames are accepted with or without at signs', function () {
    expect(InstagramCollaborators::normalize(['@apple']))->toBe(['apple'])
        ->and(InstagramCollaborators::normalize(['apple']))->toBe(['apple'])
        ->and(InstagramCollaborators::normalize(['@@apple']))->toBe(['apple'])
        ->and(InstagramCollaborators::failures(['@apple'], null))->toBe(['items' => [], 'exceedsMax' => false]);
});

test('only a leading at sign is decoration, so an email is not a username', function () {
    expect(InstagramCollaborators::isValidUsername('paulo@gmail.com'))->toBeFalse()
        ->and(InstagramCollaborators::normalize(['paulo@gmail.com']))->toBe([])
        ->and(InstagramCollaborators::normalize(['@@apple@']))->toBe([])
        ->and(InstagramCollaborators::failures(['paulo@gmail.com'], null))
        ->toBe(['items' => [0 => 'invalid'], 'exceedsMax' => false]);
});

test('a trailing newline does not satisfy the username pattern', function () {
    expect(InstagramCollaborators::isValidUsername("apple\n@"))->toBeFalse()
        ->and(InstagramCollaborators::normalize(["apple\n@"]))->toBe([])
        ->and(InstagramCollaborators::normalize(["apple\r\n@"]))->toBe([])
        ->and(InstagramCollaborators::payload(["apple\n@"]))->toBe([]);
});

test('only a list is accepted, so no string parsing can rewrite a username', function () {
    expect(InstagramCollaborators::normalize('0host,1host,2host'))->toBe([])
        ->and(InstagramCollaborators::normalize(12345))->toBe([])
        ->and(InstagramCollaborators::normalize(null))->toBe([]);
});

test('usernames that php compares as equal are still distinct handles', function () {
    expect(InstagramCollaborators::normalize(['0e1', '0e2']))->toBe(['0e1', '0e2'])
        ->and(InstagramCollaborators::normalize(['1', '01']))->toBe(['1', '01'])
        ->and(InstagramCollaborators::failures(['0e1', '0e2'], null))->toBe(['items' => [], 'exceedsMax' => false]);
});

test('normalize strips at signs, trims, and deduplicates case-insensitively', function () {
    expect(InstagramCollaborators::normalize([' @Host_One ', 'host_one', 'host_two', '', 1]))
        ->toBe(['Host_One', 'host_two']);
});

test('applyToMeta shapes the usernames and leaves other keys alone', function () {
    expect(InstagramCollaborators::applyToMeta(['collaborators' => ['@Host_One', 'host_two'], 'aspect_ratio' => '4:5']))
        ->toBe([
            'collaborators' => ['Host_One', 'host_two'],
            'aspect_ratio' => '4:5',
        ])
        ->and(InstagramCollaborators::applyToMeta(['aspect_ratio' => '4:5']))->toBe(['aspect_ratio' => '4:5']);
});

test('applyToMeta drops the leftover collaborators_with display key', function () {
    expect(InstagramCollaborators::applyToMeta([
        'collaborators' => ['host_one'],
        'collaborators_with' => 'with @host_one',
        'aspect_ratio' => '4:5',
    ]))->toBe([
        'collaborators' => ['host_one'],
        'aspect_ratio' => '4:5',
    ]);
});

test('normalize caps at three usernames', function () {
    expect(InstagramCollaborators::normalize(['a', 'b', 'c', 'd']))->toBe(['a', 'b', 'c']);
});

test('payload sends a json string the graph api can read', function () {
    expect(InstagramCollaborators::payload(['@a', 'b']))->toBe(['collaborators' => '["a","b"]'])
        ->and(InstagramCollaborators::payload([]))->toBe([])
        ->and(InstagramCollaborators::payload(['@TestUser', 'host_one'], 'testuser'))
        ->toBe(['collaborators' => '["host_one"]']);
});

test('isSameUsername ignores at signs and case', function () {
    expect(InstagramCollaborators::isSameUsername('@TestUser', 'testuser'))->toBeTrue()
        ->and(InstagramCollaborators::isSameUsername('host_one', 'host_two'))->toBeFalse()
        ->and(InstagramCollaborators::isSameUsername('host_one', null))->toBeFalse();
});

test('failures flags invalid, duplicate, self, and max', function () {
    expect(InstagramCollaborators::failures(['.user', 'a', 'A', 'b', 'c', 'd'], 'testuser'))
        ->toBe([
            'items' => [
                0 => 'invalid',
                2 => 'duplicate',
            ],
            'exceedsMax' => true,
        ])
        ->and(InstagramCollaborators::failures(['@TestUser'], 'testuser'))
        ->toBe([
            'items' => [0 => 'self'],
            'exceedsMax' => false,
        ]);
});

test('the cap is reported even when the invalid entries come first', function () {
    expect(InstagramCollaborators::failures(['!!', '##', '$$', 'a', 'b', 'c', 'd'], null))
        ->toBe([
            'items' => [0 => 'invalid', 1 => 'invalid', 2 => 'invalid'],
            'exceedsMax' => true,
        ]);
});

test('isValidUsername rejects leading, trailing, and consecutive periods', function (string $username, bool $valid) {
    expect(InstagramCollaborators::isValidUsername($username))->toBe($valid);
})->with([
    'plain' => ['host_one', true],
    'at prefix' => ['@Host.One', true],
    'underscore edges' => ['_user_', true],
    'leading period' => ['.user', false],
    'trailing period' => ['user.', false],
    'consecutive periods' => ['user..name', false],
    'at leading period' => ['@.user', false],
    'empty' => ['', false],
]);

test('the connected account never consumes one of the three slots', function () {
    expect(InstagramCollaborators::failures(['testuser', 'a', 'b', 'c'], 'testuser'))
        ->toBe(['items' => [0 => 'self'], 'exceedsMax' => false]);
});

test('the connected account is dropped before the cap, so every layer agrees', function () {
    $submitted = ['testuser', 'a', 'b', 'c'];

    expect(InstagramCollaborators::normalize($submitted, 'testuser'))->toBe(['a', 'b', 'c'])
        ->and(InstagramCollaborators::applyToMeta(['collaborators' => $submitted], 'testuser'))
        ->toBe(['collaborators' => ['a', 'b', 'c']])
        ->and(InstagramCollaborators::payload($submitted, 'testuser'))
        ->toBe(['collaborators' => '["a","b","c"]']);
});

test('normalize drops graph-invalid usernames', function () {
    expect(InstagramCollaborators::normalize(['.user', 'host_one', 'user.', 'user..name']))
        ->toBe(['host_one']);
});

test('collaborator copy treats the field as optional', function () {
    expect(__('posts.form.instagram.collaborators_hint'))
        ->toContain('Optional')
        ->toContain('must accept');
});

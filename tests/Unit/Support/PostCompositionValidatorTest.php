<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\PostCompositionValidator;
use Illuminate\Validation\ValidationException;

test('a destination inherits shared fields unless its override is explicitly empty', function () {
    $workspace = Workspace::factory()->create();
    $first = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $second = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    $composition = [
        'status' => 'draft',
        'content' => 'Shared',
        'media' => [],
        'destinations' => [
            ['social_account_id' => $first->id, 'content_type' => ContentType::InstagramFeed->value, 'meta' => []],
            ['social_account_id' => $second->id, 'content_type' => ContentType::InstagramFeed->value, 'meta' => [], 'content' => '', 'media' => []],
        ],
    ];

    $resolved = PostCompositionValidator::validate($workspace, $composition);

    expect($resolved['destinations'][0]['content'])->toBe('Shared')
        ->and($resolved['destinations'][1]['content'])->toBe('')
        ->and($resolved['destinations'][0]['social_account_id'])->toBe($first->id)
        ->and($resolved['destinations'][1]['social_account_id'])->toBe($second->id);
});

test('duplicate social accounts are rejected by account ID', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $destination = ['social_account_id' => $account->id, 'content_type' => ContentType::InstagramFeed->value, 'meta' => []];

    try {
        PostCompositionValidator::validate($workspace, [
            'status' => 'draft', 'content' => 'Hello', 'media' => [],
            'destinations' => [$destination, $destination],
        ]);
        test()->fail('Duplicate account was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.1.social_account_id');
    }
});

test('foreign workspace accounts cannot be selected', function () {
    $workspace = Workspace::factory()->create();
    $foreign = SocialAccount::factory()->instagram()->create();

    try {
        PostCompositionValidator::validate($workspace, [
            'status' => 'draft', 'content' => 'Hello', 'media' => [],
            'destinations' => [[
                'social_account_id' => $foreign->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => [],
            ]],
        ]);
        test()->fail('Foreign workspace account was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.0.social_account_id');
    }
});

test('a content type from another network is rejected', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    try {
        PostCompositionValidator::validate($workspace, [
            'status' => 'draft', 'content' => 'Hello', 'media' => [],
            'destinations' => [[
                'social_account_id' => $account->id,
                'content_type' => ContentType::XPost->value,
                'meta' => [],
            ]],
        ]);
        test()->fail('Incompatible content type was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.0.content_type');
    }
});

test('scheduling a media-required format without media is rejected', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    try {
        PostCompositionValidator::validate($workspace, [
            'status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(),
            'content' => 'Hello', 'media' => [],
            'destinations' => [[
                'social_account_id' => $account->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => [],
            ]],
        ]);
        test()->fail('Media-required post was scheduled without media.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.0.content_type');
    }
});

test('scheduled network settings and sanitized caption limits are validated per destination', function () {
    $workspace = Workspace::factory()->create();
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $workspace->id]);
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->temporaryUpload($workspace)->create();

    try {
        PostCompositionValidator::validate($workspace, [
            'status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(),
            'content' => str_repeat('x', 281), 'media' => [MediaItem::fromMedia($asset)->toArray()],
            'destinations' => [
                ['social_account_id' => $pinterest->id, 'content_type' => ContentType::PinterestPin->value, 'meta' => []],
                ['social_account_id' => $x->id, 'content_type' => ContentType::XPost->value, 'meta' => []],
            ],
        ]);
        test()->fail('Invalid scheduled destinations were accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.0.meta.board_id')
            ->toHaveKey('destinations.1.content');
    }
});

test('a destination cannot reference another workspace asset', function () {
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $foreignAsset = Media::factory()->temporaryUpload($otherWorkspace)->create();

    try {
        PostCompositionValidator::validate($workspace, [
            'status' => 'draft', 'content' => 'Hello', 'media' => [MediaItem::fromMedia($foreignAsset)->toArray()],
            'destinations' => [[
                'social_account_id' => $account->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => [],
            ]],
        ]);
        test()->fail('Foreign asset was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.0.media.0.id');
    }
});

test('a destination cannot replace an owned asset path with another path', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->temporaryUpload($workspace)->create();
    $spoofed = MediaItem::fromMedia($asset)->toArray();
    $spoofed['path'] = 'media/another-workspace/private.jpg';

    try {
        PostCompositionValidator::validate($workspace, [
            'status' => 'draft', 'content' => 'Hello', 'media' => [$spoofed],
            'destinations' => [[
                'social_account_id' => $account->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => [],
            ]],
        ]);
        test()->fail('Spoofed asset path was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.0.media.0.id');
    }
});

test('a destination cannot replace an owned asset URL', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->temporaryUpload($workspace)->create();
    $spoofed = MediaItem::fromMedia($asset)->toArray();
    $spoofed['url'] = 'https://untrusted.example/image.jpg';

    try {
        PostCompositionValidator::validate($workspace, [
            'status' => 'draft', 'content' => 'Hello', 'media' => [$spoofed],
            'destinations' => [[
                'social_account_id' => $account->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => [],
            ]],
        ]);
        test()->fail('Spoofed asset URL was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.0.media.0.url');
    }
});

test('an owned image with valid network settings can be scheduled', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->pinterest()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->temporaryUpload($workspace)->create();

    $resolved = PostCompositionValidator::validate($workspace, [
        'status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Pin caption', 'media' => [MediaItem::fromMedia($asset)->toArray()],
        'destinations' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::PinterestPin->value,
            'meta' => ['board_id' => 'board-123'],
        ]],
    ]);

    expect($resolved['destinations'][0]['media'][0]['id'])->toBe($asset->id);
});

test('owned media metadata comes from the asset while per-post alt text and user tags are retained', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->temporaryUpload($workspace)->create(['meta' => ['width' => 1080, 'height' => 1080]]);
    $spoofed = MediaItem::fromMedia($asset)->toArray();
    $spoofed['type'] = 'video';
    $spoofed['mime_type'] = 'video/mp4';
    $spoofed['size'] = 1;
    $spoofed['meta'] = [
        'width' => 1,
        'height' => 1,
        'alt_text' => 'Accessible caption',
        'user_tags' => [['username' => 'trypost', 'x' => 0.5, 'y' => 0.25]],
    ];

    $resolved = PostCompositionValidator::validate($workspace, [
        'status' => 'draft', 'content' => 'Hello', 'media' => [$spoofed],
        'destinations' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::InstagramFeed->value,
            'meta' => [],
        ]],
    ]);

    expect($resolved['destinations'][0]['media'][0]['type'])->toBe($asset->type->value)
        ->and($resolved['destinations'][0]['media'][0]['mime_type'])->toBe($asset->mime_type)
        ->and($resolved['destinations'][0]['media'][0]['size'])->toBe($asset->size)
        ->and($resolved['destinations'][0]['media'][0]['meta'])
        ->toEqual([
            'width' => 1080,
            'height' => 1080,
            'alt_text' => 'Accessible caption',
            'user_tags' => [['username' => 'trypost', 'x' => 0.5, 'y' => 0.25]],
        ]);
});

test('the X length check uses the sanitized and defused caption', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $resolved = PostCompositionValidator::validate($workspace, [
        'status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'https://example.com '.str_repeat('x', 265), 'media' => [],
        'destinations' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::XPost->value,
            'meta' => [],
        ]],
    ]);

    expect($resolved['destinations'][0]['content'])->toStartWith('https://example.com');
});

test('media already on the post may change its alt text and people tags, in any key order, but nothing else', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $existing = [
        'id' => 'legacy-image',
        'path' => 'uploads/legacy.jpg',
        'url' => 'https://cdn.test/legacy.jpg',
        'type' => 'image',
        'meta' => ['width' => 1080, 'height' => 1080, 'alt_text' => 'Old'],
    ];
    $composition = fn (array $media): array => [
        'status' => 'draft',
        'content' => 'Hello',
        'media' => [$media],
        'destinations' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::InstagramFeed->value,
            'meta' => [],
        ]],
    ];

    $edited = [
        'url' => 'https://cdn.test/legacy.jpg',
        'id' => 'legacy-image',
        'type' => 'image',
        'path' => 'uploads/legacy.jpg',
        'meta' => [
            'alt_text' => 'New',
            'height' => 1080,
            'width' => 1080,
            'user_tags' => [['username' => 'trypost', 'x' => 0.5, 'y' => 0.5]],
        ],
    ];
    $resolved = PostCompositionValidator::validate($workspace, $composition($edited), [$existing]);

    expect($resolved['destinations'][0]['media'][0]['meta'])->toEqual($edited['meta']);

    $moved = [...$edited, 'url' => 'https://cdn.test/other.jpg'];
    expect(fn () => PostCompositionValidator::validate($workspace, $composition($moved), [$existing]))
        ->toThrow(ValidationException::class);
});

test('an instagram story schedules with more than five hashtags while a feed post does not', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->temporaryUpload($workspace)->create(['meta' => ['width' => 1080, 'height' => 1920]]);
    $composition = fn (ContentType $type): array => [
        'status' => 'scheduled', 'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Launch #a #b #c #d #e #f', 'media' => [MediaItem::fromMedia($asset)->toArray()],
        'destinations' => [['social_account_id' => $account->id, 'content_type' => $type->value, 'meta' => []]],
    ];

    $resolved = PostCompositionValidator::validate($workspace, $composition(ContentType::InstagramStory));

    expect($resolved['destinations'][0]['content'])->toBe('Launch #a #b #c #d #e #f');

    try {
        PostCompositionValidator::validate($workspace, $composition(ContentType::InstagramFeed));
        test()->fail('Six hashtags were accepted on a feed post.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('destinations.0.content');
    }
});

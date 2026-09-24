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
    $first = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $second = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);

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
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
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

test('inactive and foreign workspace accounts cannot be selected', function () {
    $workspace = Workspace::factory()->create();
    $inactive = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => false]);
    $foreign = SocialAccount::factory()->instagram()->create(['is_active' => true]);

    foreach ([$inactive, $foreign] as $account) {
        try {
            PostCompositionValidator::validate($workspace, [
                'status' => 'draft', 'content' => 'Hello', 'media' => [],
                'destinations' => [[
                    'social_account_id' => $account->id,
                    'content_type' => ContentType::InstagramFeed->value,
                    'meta' => [],
                ]],
            ]);
            test()->fail('Unavailable account was accepted.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('destinations.0.social_account_id');
        }
    }
});

test('a content type from another network is rejected', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);

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
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);

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
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create();

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
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $foreignAsset = Media::factory()->assets()->for($otherWorkspace, 'mediable')->create();

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
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create();
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
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create();
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
    $account = SocialAccount::factory()->pinterest()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create();

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

test('owned media metadata comes from the asset while per-post alt text is retained', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create(['meta' => ['width' => 1080, 'height' => 1080]]);
    $spoofed = MediaItem::fromMedia($asset)->toArray();
    $spoofed['type'] = 'video';
    $spoofed['mime_type'] = 'video/mp4';
    $spoofed['size'] = 1;
    $spoofed['meta'] = ['width' => 1, 'height' => 1, 'alt_text' => 'Accessible caption'];

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
        ->toEqual(['width' => 1080, 'height' => 1080, 'alt_text' => 'Accessible caption']);
});

test('the X length check uses the sanitized and defused caption', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'is_active' => true]);

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

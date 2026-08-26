<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostPlatformMetaRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

test('custom meta messages cover pinterest and instagram collaborator fields', function () {
    expect(PostPlatformMetaRules::messages())->toBe([
        'platforms.*.meta.link.url' => __('posts.form.pinterest.link_invalid'),
        'platforms.*.meta.link.max' => __('posts.form.pinterest.link_max'),
        'platforms.*.meta.title.max' => __('posts.form.pinterest.title_max'),
    ]);
});

test('custom meta attributes rename pinterest and instagram collaborator fields', function () {
    expect(PostPlatformMetaRules::attributes())->toBe([
        'platforms.*.meta.title' => __('posts.form.pinterest.title'),
        'platforms.*.meta.link' => __('posts.form.pinterest.link'),
        'platforms.*.meta.collaborators' => __('posts.form.instagram.collaborators'),
    ]);
});

test('shared meta rules still include non-pinterest platform fields', function () {
    $rules = PostPlatformMetaRules::rules();

    expect($rules)->toHaveKeys([
        'platforms.*.meta.aspect_ratio',
        'platforms.*.meta.collaborators',
        'platforms.*.meta.privacy_level',
        'platforms.*.meta.board_id',
        'platforms.*.meta.channel_id',
        'platforms.*.meta.title',
        'platforms.*.meta.link',
    ]);
});

test('the meta rules share one read per platform row instead of one per rule', function () {
    $post = Post::factory()->create([
        'workspace_id' => Workspace::factory(),
        'user_id' => User::factory(),
    ]);
    $platforms = collect(range(1, 5))->map(fn (): PostPlatform => PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
    ]));

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Validator::make([
        'platforms' => $platforms->map(fn (PostPlatform $platform): array => [
            'id' => $platform->id,
            'meta' => ['collaborators' => ['a'], 'mentions' => []],
        ])->all(),
    ], ['platforms' => ['sometimes', 'array']] + PostPlatformMetaRules::rules())->fails();

    expect($queries)->toBe($platforms->count() * 2);
});

test('normalize strips instagram collaborators only on instagram platforms', function () {
    $incoming = ['collaborators' => ['@Host_One', 'host_two'], 'aspect_ratio' => '4:5'];

    expect(PostPlatformMetaRules::normalize(Platform::Instagram, $incoming))->toBe([
        'collaborators' => ['Host_One', 'host_two'],
        'aspect_ratio' => '4:5',
    ])
        ->and(PostPlatformMetaRules::normalize(Platform::InstagramFacebook, $incoming))
        ->toBe(PostPlatformMetaRules::normalize(Platform::Instagram, $incoming));
});

test('normalize leaves other networks meta untouched so future mentions stay intact', function () {
    $incoming = ['collaborators' => ['@Host_One'], 'mentions' => ['@someone']];

    expect(PostPlatformMetaRules::normalize(Platform::TikTok, $incoming))->toBe($incoming)
        ->and(PostPlatformMetaRules::normalize(Platform::YouTube, $incoming))->toBe($incoming)
        ->and(PostPlatformMetaRules::normalize(null, $incoming))->toBe($incoming);
});

test('merge applies instagram normalize then keeps existing keys', function () {
    expect(PostPlatformMetaRules::merge(
        Platform::Instagram,
        ['aspect_ratio' => '4:5'],
        ['collaborators' => ['@Host_One']],
    ))->toBe([
        'aspect_ratio' => '4:5',
        'collaborators' => ['Host_One'],
    ]);
});

test('merge drops leftover collaborators_with from stored instagram meta', function () {
    expect(PostPlatformMetaRules::merge(
        Platform::Instagram,
        ['collaborators' => ['Host_One'], 'collaborators_with' => 'with @Host_One', 'aspect_ratio' => '4:5'],
        ['collaborators' => ['host_two']],
    ))->toMatchArray([
        'aspect_ratio' => '4:5',
        'collaborators' => ['host_two'],
    ])
        ->not->toHaveKey('collaborators_with');
});

test('merge clears instagram collaborators on stories', function () {
    expect(PostPlatformMetaRules::merge(
        Platform::Instagram,
        ['collaborators' => ['Host_One'], 'aspect_ratio' => '4:5'],
        ['collaborators' => ['host_two']],
        ContentType::InstagramStory,
    ))->toMatchArray([
        'aspect_ratio' => '4:5',
        'collaborators' => [],
    ]);
});

test('merge clears instagram collaborators on stories even when incoming meta is empty', function () {
    expect(PostPlatformMetaRules::merge(
        Platform::Instagram,
        ['collaborators' => ['Host_One'], 'aspect_ratio' => '4:5'],
        [],
        ContentType::InstagramStory,
    ))->toMatchArray([
        'aspect_ratio' => '4:5',
        'collaborators' => [],
    ]);
});

test('only instagram stories drop collaborators', function () {
    expect(PostPlatformMetaRules::dropsCollaborators(ContentType::InstagramStory))->toBeTrue()
        ->and(PostPlatformMetaRules::dropsCollaborators(ContentType::InstagramStory->value))->toBeTrue()
        ->and(PostPlatformMetaRules::dropsCollaborators(ContentType::InstagramReel))->toBeFalse()
        ->and(PostPlatformMetaRules::dropsCollaborators(ContentType::LinkedInPost))->toBeFalse()
        ->and(PostPlatformMetaRules::dropsCollaborators(ContentType::FacebookStory))->toBeFalse()
        ->and(PostPlatformMetaRules::dropsCollaborators(null))->toBeFalse();
});

test('platformForAttribute uses the social account network on create and update', function () {
    $workspace = Workspace::factory()->create();
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => User::factory(),
    ]);
    $instagramRow = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'platform' => Platform::LinkedIn,
    ]);
    $tiktokRow = PostPlatform::factory()->tiktok()->create([
        'post_id' => $post->id,
        'social_account_id' => $tiktok->id,
    ]);

    expect(PostPlatformMetaRules::platformForAttribute(
        ['platforms' => [['social_account_id' => $instagram->id]]],
        'platforms.0.meta.collaborators',
    ))->toBe(Platform::Instagram)
        ->and(PostPlatformMetaRules::platformForAttribute(
            ['platforms' => [['id' => $instagramRow->id]]],
            'platforms.0.meta.collaborators',
        ))->toBe(Platform::Instagram)
        ->and(PostPlatformMetaRules::platformForAttribute(
            ['platforms' => [['id' => $tiktokRow->id]]],
            'platforms.0.meta.mentions',
        ))->toBe(Platform::TikTok)
        ->and(PostPlatformMetaRules::platformForAttribute(
            ['platforms' => [['id' => $instagramRow->id, 'social_account_id' => $tiktok->id]]],
            'platforms.0.meta.collaborators',
        ))->toBe(Platform::Instagram)
        ->and(PostPlatformMetaRules::platformForAttribute(
            ['platforms' => [['id' => $instagramRow->id, 'social_account_id' => '00000000-0000-4000-8000-000000000000']]],
            'platforms.0.meta.collaborators',
        ))->toBe(Platform::Instagram)
        ->and(PostPlatformMetaRules::platformOf($instagramRow->load('socialAccount')))->toBe(Platform::Instagram);
});

test('instagram factory states default to feed content type', function () {
    expect(PostPlatform::factory()->instagram()->make()->content_type)->toBe(ContentType::InstagramFeed)
        ->and(PostPlatform::factory()->instagramFacebook()->make()->content_type)->toBe(ContentType::InstagramFeed);
});

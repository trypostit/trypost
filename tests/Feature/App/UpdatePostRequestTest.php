<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\GoogleBusiness\TopicType;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\PublishPost;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('youtube description checks effective web metadata before scheduling or publishing', function (string $patch, bool $allowed, string $status) {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->youtube()->create([
        'user_id' => $this->user->id,
        'content' => 'Short title',
        'status' => Status::Draft,
        'media' => $this->mediaPayload,
        'meta' => ['description' => str_repeat('a', 5001)],
    ]);
    $data = [
        'status' => $status,
        'scheduled_at' => now()->addHour()->toIso8601String(),
        'media' => $this->mediaPayload,
        'content_type' => ContentType::YouTubeShort->value,
    ];

    if ($patch !== 'omit') {
        $data['meta'] = $patch === 'row' ? [] : ['description' => $patch === 'clear' ? null : 'Valid description'];
    }
    Queue::fake();
    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), $data);

    if ($allowed) {
        $response->assertSessionHasNoErrors();
        expect($post->fresh()->status->value)->toBe($status);

        if ($status === Status::Publishing->value) {
            Queue::assertPushed(PublishPost::class);
        }
    } else {
        $response->assertSessionHasErrors('meta.description');
        expect($post->fresh()->status)->toBe(Status::Draft);
        Queue::assertNotPushed(PublishPost::class);
    }
})->with([
    'stored invalid description' => ['omit', false],
    'retained invalid description' => ['row', false],
    'replaced description' => ['replace', true],
    'cleared description' => ['clear', true],
])->with([Status::Scheduled->value, Status::Publishing->value]);

test('youtube description is validated and persisted on a draft', function () {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->youtube()->create(['user_id' => $this->user->id, 'meta' => []]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Draft->value,
        'meta' => ['description' => str_repeat('é', 2501)],
    ])->assertSessionHasErrors('meta.description');
    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Draft->value,
        'content' => 'Short title',
        'meta' => ['description' => str_repeat('é', 2500)],
    ])->assertSessionHasNoErrors();
    expect(data_get($post->fresh()->meta, 'description'))->toBe(str_repeat('é', 2500))
        ->and($post->fresh()->content)->toBe('Short title');
});

test('youtube description update reports one validation message', function (bool $hasSubmittedError) {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->youtube()->create([
        'user_id' => $this->user->id,
        'meta' => ['description' => str_repeat('a', 5001)],
    ]);
    $data = [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addHour()->toIso8601String(),
        'content' => 'Short title',
        'media' => $this->mediaPayload,
        'content_type' => ContentType::YouTubeShort->value,
    ];

    if ($hasSubmittedError) {
        $data['meta'] = ['description' => str_repeat('é', 2501)];
    }

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), $data);
    $key = 'meta.description';
    $response->assertSessionHasErrors($key);

    expect(session('errors')->get($key))->toBe([
        __('posts.form.youtube.description_max'),
    ]);
})->with([
    'stored invalid description' => [false],
    'submitted invalid description' => [true],
]);

test('youtube description validation rolls back the edit', function () {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->youtube()->create([
        'user_id' => $this->user->id,
        'content' => 'Original title',
        'meta' => ['description' => str_repeat('a', 5001)],
    ]);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addHour()->toIso8601String(),
        'content' => 'Changed title',
        'media' => $this->mediaPayload,
        'content_type' => ContentType::YouTubeShort->value,
    ])->assertSessionHasErrors('meta.description');

    expect($post->fresh()->status)->toBe(Status::Draft)
        ->and($post->fresh()->content)->toBe('Original title')
        ->and($post->fresh()->scheduled_at)->toBeNull();
});

beforeEach(function () {
    Storage::fake();
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    // Media payload used by tests that need to satisfy ContentTypeCompatibleWithMedia.
    $video = Media::factory()->stored()->video()->temporaryUpload($this->workspace)->create([
        'size' => 100_000,
        'meta' => ['duration' => 30],
    ]);
    $this->mediaPayload = [MediaItem::fromMedia($video)->toArray()];
    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok,
    ]);
    $this->post = Post::factory()->forAccount($this->socialAccount, ContentType::TikTokVideo)->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);
});

test('publishing a tiktok post without privacy_level is rejected', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $this->mediaPayload,
            'content_type' => ContentType::TikTokVideo->value,
            'meta' => [],
        ]);

    $response->assertSessionHasErrors('meta.privacy_level');
});

test('publishing a tiktok post with privacy_level passes privacy_level validation', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $this->mediaPayload,
            'content_type' => ContentType::TikTokVideo->value,
            'meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value],
        ]);

    $response->assertSessionDoesntHaveErrors(['meta.privacy_level']);
});

test('scheduling a bluesky post with a mov video is not rejected on format', function () {
    $blueskyAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
    ]);
    $blueskyPost = Post::factory()->forAccount($blueskyAccount)->create([
        'user_id' => $this->user->id,
        'platform' => Platform::Bluesky,
        'content_type' => ContentType::BlueskyPost,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $blueskyPost), [
            'status' => Status::Scheduled->value,
            'scheduled_at' => now()->addHour()->toIso8601String(),
            'media' => [[
                'id' => 'test-media-mov',
                'path' => 'media/2026-01/clip.mov',
                'url' => 'https://example.com/media/2026-01/clip.mov',
                'type' => 'video',
                'mime_type' => 'video/quicktime',
                'original_filename' => 'clip.mov',
            ]],
            'content_type' => ContentType::BlueskyPost->value,
        ]);

    $response->assertSessionDoesntHaveErrors(['content_type']);
});

test('publishing a bluesky post with an oversized video is rejected server-side', function () {
    $blueskyAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
    ]);
    $blueskyPost = Post::factory()->forAccount($blueskyAccount)->create([
        'user_id' => $this->user->id,
        'platform' => Platform::Bluesky,
        'content_type' => ContentType::BlueskyPost,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $blueskyPost), [
            'status' => Status::Publishing->value,
            'media' => [[
                'id' => 'test-media-big',
                'path' => 'media/2026-01/big.mp4',
                'url' => 'https://example.com/media/2026-01/big.mp4',
                'type' => 'video',
                'mime_type' => 'video/mp4',
                'original_filename' => 'big.mp4',
                'size' => 300_000_001,
            ]],
            'content_type' => ContentType::BlueskyPost->value,
        ]);

    // Bluesky's cap is decimal, so both numbers render in decimal units.
    $response->assertSessionHasErrors([
        'content_type' => trans('posts.form.warnings.video_too_large', ['max' => '300 MB', 'current' => '300.0 MB']),
    ]);
});

test('publishing a bluesky post with a video over the duration cap is rejected server-side', function () {
    $blueskyAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
    ]);
    $blueskyPost = Post::factory()->forAccount($blueskyAccount)->create([
        'user_id' => $this->user->id,
        'platform' => Platform::Bluesky,
        'content_type' => ContentType::BlueskyPost,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $blueskyPost), [
            'status' => Status::Publishing->value,
            'media' => [[
                'id' => 'test-media-long',
                'path' => 'media/2026-01/long.mp4',
                'url' => 'https://example.com/media/2026-01/long.mp4',
                'type' => 'video',
                'mime_type' => 'video/mp4',
                'original_filename' => 'long.mp4',
                'size' => 50_000_000,
                'meta' => ['duration' => 601.5],
            ]],
            'content_type' => ContentType::BlueskyPost->value,
        ]);

    $response->assertSessionHasErrors(['content_type' => 'Video is 10min 2s long, but this post type allows up to 10min.']);
});

test('saving a draft does not enforce media compatibility', function () {
    $blueskyAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
    ]);
    $blueskyPost = Post::factory()->forAccount($blueskyAccount)->create([
        'user_id' => $this->user->id,
        'platform' => Platform::Bluesky,
        'content_type' => ContentType::BlueskyPost,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $blueskyPost), [
            'status' => Status::Draft->value,
            'media' => [[
                'id' => 'test-media-mov',
                'path' => 'media/2026-01/clip.mov',
                'url' => 'https://example.com/media/2026-01/clip.mov',
                'type' => 'video',
                'mime_type' => 'video/quicktime',
                'original_filename' => 'clip.mov',
            ]],
            'content_type' => ContentType::BlueskyPost->value,
        ]);

    $response->assertSessionDoesntHaveErrors(['content_type']);
});

test('publishing a pinterest post without board_id is rejected', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterest()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $mediaPayload = [
        [
            'id' => 'test-image',
            'path' => 'media/2026-01/pin.jpg',
            'url' => 'https://example.com/media/2026-01/pin.jpg',
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'pin.jpg',
        ],
    ];

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Publishing->value,
            'media' => $mediaPayload,
            'content_type' => ContentType::PinterestPin->value,
            'meta' => [],
        ]);

    $response->assertSessionHasErrors('meta.board_id');
});

test('publishing a pinterest post with board_id passes board validation', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterest()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $mediaPayload = [
        [
            'id' => 'test-image',
            'path' => 'media/2026-01/pin.jpg',
            'url' => 'https://example.com/media/2026-01/pin.jpg',
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'pin.jpg',
        ],
    ];

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Publishing->value,
            'media' => $mediaPayload,
            'content_type' => ContentType::PinterestPin->value,
            'meta' => ['board_id' => '123456789'],
        ]);

    $response->assertSessionDoesntHaveErrors(['meta.board_id']);
});

test('scheduling a pinterest post without board_id is rejected', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterest()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $mediaPayload = [
        [
            'id' => 'test-image',
            'path' => 'media/2026-01/pin.jpg',
            'url' => 'https://example.com/media/2026-01/pin.jpg',
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'pin.jpg',
        ],
    ];

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Scheduled->value,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'media' => $mediaPayload,
            'content_type' => ContentType::PinterestPin->value,
            'meta' => [],
        ]);

    $response->assertSessionHasErrors('meta.board_id');
});

test('publishing a pinterest carousel without board_id is rejected', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterestCarousel()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $mediaPayload = [
        [
            'id' => 'img-1',
            'path' => 'media/2026-01/img1.jpg',
            'url' => 'https://example.com/media/2026-01/img1.jpg',
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'img1.jpg',
        ],
        [
            'id' => 'img-2',
            'path' => 'media/2026-01/img2.jpg',
            'url' => 'https://example.com/media/2026-01/img2.jpg',
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'img2.jpg',
        ],
    ];

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Publishing->value,
            'media' => $mediaPayload,
            'content_type' => ContentType::PinterestCarousel->value,
            'meta' => [],
        ]);

    $response->assertSessionHasErrors('meta.board_id');
});

test('publishing a pinterest video pin without board_id is rejected', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterestVideoPin()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Publishing->value,
            'media' => $this->mediaPayload,
            'content_type' => ContentType::PinterestVideoPin->value,
            'meta' => [],
        ]);

    $response->assertSessionHasErrors('meta.board_id');
});

test('saving a pinterest post as draft without board_id skips the board rule', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterest()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Draft->value,
            'content_type' => ContentType::PinterestPin->value,
            'meta' => [],
        ]);

    $response->assertSessionDoesntHaveErrors(['meta.board_id']);
});

test('publishing a tiktok post with an unknown privacy_level is rejected', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $this->mediaPayload,
            'content_type' => ContentType::TikTokVideo->value,
            'meta' => ['privacy_level' => 'EVERYONE'],
        ]);

    $response->assertSessionHasErrors('meta.privacy_level');
});

test('publishing a tiktok post as self only branded content is rejected', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $this->mediaPayload,
            'content_type' => ContentType::TikTokVideo->value,
            'meta' => [
                'privacy_level' => PrivacyLevel::SelfOnly->value,
                'brand_content_toggle' => true,
            ],
        ]);

    $response->assertSessionHasErrors(['meta.privacy_level' => trans('posts.form.tiktok.privacy.private_disabled_branded')]);
});

test('saving a tiktok post as draft without privacy_level skips the privacy_level rule', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'content_type' => ContentType::TikTokVideo->value,
            'meta' => [],
        ]);

    $response->assertSessionDoesntHaveErrors(['meta.privacy_level']);
});

test('scheduling a threads post over 500 chars is rejected with the platform name', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $threadsPost = Post::factory()->forAccount($threadsAccount)->threads()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $threadsPost), [
            'status' => Status::Scheduled->value,
            'content' => str_repeat('a', 537),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'content_type' => ContentType::ThreadsPost->value,
            'meta' => [],
        ]);

    $response->assertSessionHasErrors('content');
    expect(session('errors')->get('content')[0])
        ->toContain('Threads')
        ->toContain('500')
        ->toContain('37'); // over by 37
});

test('scheduling a threads post within 500 chars passes content-length validation', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $threadsPost = Post::factory()->forAccount($threadsAccount)->threads()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $threadsPost), [
            'status' => Status::Scheduled->value,
            'content' => str_repeat('a', 500),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'content_type' => ContentType::ThreadsPost->value,
            'meta' => [],
        ]);

    $response->assertSessionDoesntHaveErrors('content');
});

test('saving an over-limit threads post as draft skips the content-length rule', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $threadsPost = Post::factory()->forAccount($threadsAccount)->threads()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $threadsPost), [
            'status' => Status::Draft->value,
            'content' => str_repeat('a', 1000),
            'content_type' => ContentType::ThreadsPost->value,
            'meta' => [],
        ]);

    $response->assertSessionDoesntHaveErrors('content');
});

test('draft save accepts media source metadata for ai regeneration', function () {
    $asset = Media::factory()->stored()->temporaryUpload($this->workspace)->create([
        'path' => 'ai-images/generated.webp',
        'original_filename' => 'generated.webp',
        'mime_type' => 'image/webp',
    ]);
    $payload = [
        [
            ...MediaItem::fromMedia($asset)->toArray(),
            'source' => 'ai',
            'source_meta' => [
                'title' => 'Fix ECP typo',
                'body' => 'Body copy',
                'keywords' => ['marketing', 'automation'],
                'width' => 1080,
                'height' => 1350,
            ],
        ],
    ];

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'media' => $payload,
        ]);

    $response->assertSessionDoesntHaveErrors();

    $this->post->refresh();
    expect(data_get($this->post->media, '0.source'))->toBe('ai');
    expect(data_get($this->post->media, '0.source_meta.title'))->toBe('Fix ECP typo');
});

test('the web update accepts a compatible type change for the fixed account', function () {
    $this->socialAccount->update(['platform' => Platform::Instagram]);
    $this->post->update([
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
    ]);
    $video = Media::factory()->stored()->video()->temporaryUpload($this->workspace)->create();

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Scheduled->value,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'content' => 'Reel atualizado',
            'media' => [MediaItem::fromMedia($video)->toArray()],
            'content_type' => ContentType::InstagramReel->value,
        ]);

    $response->assertSessionDoesntHaveErrors();
    expect($this->post->fresh()->content_type)->toBe(ContentType::InstagramReel)
        ->and($this->post->fresh()->content)->toBe('Reel atualizado');
});

test('instagram_carousel is rejected as a content_type — carousel is a feed post with multiple images', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'content_type' => 'instagram_carousel',
            'meta' => [],
        ]);

    $response->assertSessionHasErrors('content_type');
});

test('publishing a discord post without a channel is rejected', function () {
    $account = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'platform' => Platform::Discord,
        'content_type' => ContentType::DiscordMessage,
        'meta' => [],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content_type' => ContentType::DiscordMessage->value,
            'meta' => [],
        ])
        ->assertSessionHasErrors('meta.channel_id');
});

test('saving a discord draft without a channel is allowed', function () {
    $account = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'platform' => Platform::Discord,
        'content_type' => ContentType::DiscordMessage,
        'meta' => [],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Draft->value,
            'content_type' => ContentType::DiscordMessage->value,
            'meta' => [],
        ])
        ->assertSessionDoesntHaveErrors('meta.channel_id');
});

test('saving a pinterest draft persists title and link meta', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterest()->create([
        'user_id' => $this->user->id,
        'meta' => ['board_id' => 'board-1'],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Draft->value,
            'content' => 'Shared caption',
            'content_type' => ContentType::PinterestPin->value,
            'meta' => [
                'board_id' => 'board-1',
                'title' => 'Pin Title',
                'link' => 'https://example.com/product',
            ],
        ])
        ->assertSessionDoesntHaveErrors();

    $meta = $pinterestPost->fresh()->meta;

    expect(data_get($meta, 'title'))->toBe('Pin Title')
        ->and(data_get($meta, 'link'))->toBe('https://example.com/product')
        ->and(array_key_exists('description', $meta))->toBeFalse();
});

test('clearing pinterest title and link removes the meta keys', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterest()->create([
        'user_id' => $this->user->id,
        'meta' => [
            'board_id' => 'board-1',
            'title' => 'Keep me gone',
            'link' => 'https://example.com/gone',
        ],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Draft->value,
            'content_type' => ContentType::PinterestPin->value,
            'meta' => [
                'board_id' => 'board-1',
                'title' => null,
                'link' => null,
            ],
        ])
        ->assertSessionDoesntHaveErrors();

    $meta = $pinterestPost->fresh()->meta;

    expect(data_get($meta, 'board_id'))->toBe('board-1')
        ->and(array_key_exists('title', $meta))->toBeFalse()
        ->and(array_key_exists('link', $meta))->toBeFalse();
});

test('pinterest rejects invalid link when scheduling', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterest()->create([
        'user_id' => $this->user->id,
        'meta' => ['board_id' => 'board-1'],
    ]);

    $mediaPayload = [
        [
            'id' => 'test-image',
            'path' => 'media/2026-01/pin.jpg',
            'url' => 'https://example.com/media/2026-01/pin.jpg',
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'pin.jpg',
        ],
    ];

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Scheduled->value,
            'scheduled_at' => now()->addHour()->toIso8601String(),
            'content' => 'Ready to schedule',
            'media' => $mediaPayload,
            'content_type' => ContentType::PinterestPin->value,
            'meta' => [
                'board_id' => 'board-1',
                'link' => 'not-a-url',
            ],
        ])
        ->assertSessionHasErrors([
            'meta.link' => __('posts.form.pinterest.link_invalid'),
        ]);
});

test('pinterest meta title and link validation bounds are enforced', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPost = Post::factory()->forAccount($pinterestAccount)->pinterest()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $pinterestPost), [
            'status' => Status::Draft->value,
            'content_type' => ContentType::PinterestPin->value,
            'meta' => [
                'title' => str_repeat('t', 101),
                'link' => 'not-a-url',
            ],
        ])
        ->assertSessionHasErrors([
            'meta.title' => __('posts.form.pinterest.title_max'),
            'meta.link' => __('posts.form.pinterest.link_invalid'),
        ]);
});

test('saving a google business event title over the api length is rejected', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Draft->value,
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'EVENT',
                'event' => ['title' => str_repeat('t', TopicType::TITLE_MAX_LENGTH + 1)],
            ],
        ])
        ->assertSessionHasErrors([
            'meta.event.title' => __('posts.form.google_business.title_max'),
        ]);
});

test('saving a google business coupon longer than the event title cap is accepted', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Draft->value,
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'OFFER',
                'offer' => ['coupon_code' => str_repeat('C', TopicType::TITLE_MAX_LENGTH + 20)],
            ],
        ])
        ->assertSessionDoesntHaveErrors();

    expect(data_get($post->fresh()->meta, 'offer.coupon_code'))
        ->toBe(str_repeat('C', TopicType::TITLE_MAX_LENGTH + 20));
});

test('publishing a google business event post without event fields is rejected', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => ['topic_type' => 'EVENT'],
        ]);

    $response->assertSessionHasErrors('meta.event.title');
});

test('publishing a google business offer post round-trips topic_type and offer meta', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content' => 'Visit us',
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'OFFER',
                'event' => ['title' => 'Summer Sale', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
                'offer' => ['coupon_code' => 'SAVE10'],
            ],
        ]);

    $response->assertSessionDoesntHaveErrors();

    $meta = $post->fresh()->meta;

    expect(data_get($meta, 'topic_type'))->toBe('OFFER')
        ->and(data_get($meta, 'event.title'))->toBe('Summer Sale')
        ->and(data_get($meta, 'offer.coupon_code'))->toBe('SAVE10');
});

test('publishing a google business offer post requires the event title', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'OFFER',
                'offer' => ['coupon_code' => 'SAVE10'],
            ],
        ]);

    $response->assertSessionHasErrors([
        'meta.event.title' => __('posts.form.google_business.offer_title_required'),
    ]);
});

test('publishing a google business offer post without dates is rejected', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'OFFER',
                'event' => ['title' => 'Summer Sale'],
            ],
        ]);

    $response->assertSessionHasErrors('meta.event.start_date');
});

test('publishing a google business event with the end date before the start is rejected', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'EVENT',
                'event' => ['title' => 'Sale', 'start_date' => '2026-09-10', 'end_date' => '2026-09-01'],
            ],
        ]);

    $response->assertSessionHasErrors('meta.event.end_date');
});

test('publishing a google business event with a same-day end time before start is rejected', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'EVENT',
                'event' => [
                    'title' => 'Sale',
                    'start_date' => '2026-09-01',
                    'end_date' => '2026-09-01',
                    'start_time' => '18:00',
                    'end_time' => '09:00',
                ],
            ],
        ]);

    $response->assertSessionHasErrors([
        'meta.event.end_time' => __('posts.form.google_business.event_end_time_before_start'),
    ]);
});

test('publishing a google business event persists start and end times', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content' => 'Visit us',
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'EVENT',
                'event' => [
                    'title' => 'Grand Opening',
                    'start_date' => '2026-09-01',
                    'end_date' => '2026-09-02',
                    'start_time' => '09:30',
                    'end_time' => '17:00',
                ],
            ],
        ]);

    $response->assertSessionDoesntHaveErrors();

    $meta = $post->fresh()->meta;

    expect(data_get($meta, 'event.start_time'))->toBe('09:30')
        ->and(data_get($meta, 'event.end_time'))->toBe('17:00');
});

test('publishing a google business post with a url-needing cta and no url is rejected', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->create([
        'user_id' => $this->user->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $post), [
            'status' => Status::Publishing->value,
            'content_type' => ContentType::GoogleBusinessPost->value,
            'meta' => [
                'topic_type' => 'STANDARD',
                'call_to_action' => ['action_type' => 'BOOK'],
            ],
        ]);

    $response->assertSessionHasErrors('meta.call_to_action.url');
});

test('scheduling a channel post without content_type keeps its stored type', function () {
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'content' => 'Ready',
        'status' => Status::Draft,
    ]);
    $scheduledAt = now()->addDay()->startOfMinute();

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Scheduled->value,
        'content' => 'Ready to go',
        'scheduled_at' => $scheduledAt->toIso8601String(),
    ])->assertSessionHasNoErrors();

    expect($post->fresh())
        ->status->toBe(Status::Scheduled)
        ->content_type->toBe(ContentType::LinkedInPost)
        ->content->toBe('Ready to go');
});

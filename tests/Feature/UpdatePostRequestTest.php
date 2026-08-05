<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    // Media payload used by tests that need to satisfy ContentTypeCompatibleWithMedia.
    $this->mediaPayload = [
        [
            'id' => 'test-media-video',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'type' => 'video',
            'mime_type' => 'video/mp4',
            'original_filename' => 'test-video.mp4',
        ],
    ];
    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok,
    ]);
    $this->postPlatform = PostPlatform::factory()->tiktok()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        // Override factory default so we control privacy_level per test.
        'meta' => [],
    ]);
});

test('publishing a tiktok post without privacy_level is rejected', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $this->mediaPayload,
            'platforms' => [
                [
                    'id' => $this->postPlatform->id,
                    'content_type' => ContentType::TikTokVideo->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionHasErrors('platforms.0.meta.privacy_level');
});

test('publishing a tiktok post with privacy_level passes privacy_level validation', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $this->mediaPayload,
            'platforms' => [
                [
                    'id' => $this->postPlatform->id,
                    'content_type' => ContentType::TikTokVideo->value,
                    'meta' => ['privacy_level' => 'SELF_ONLY'],
                ],
            ],
        ]);

    $response->assertSessionDoesntHaveErrors(['platforms.0.meta.privacy_level']);
});

test('publishing a pinterest post without board_id is rejected', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
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
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $mediaPayload,
            'platforms' => [
                [
                    'id' => $pinterestPlatform->id,
                    'content_type' => ContentType::PinterestPin->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionHasErrors('platforms.0.meta.board_id');
});

test('publishing a pinterest post with board_id passes board validation', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
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
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $mediaPayload,
            'platforms' => [
                [
                    'id' => $pinterestPlatform->id,
                    'content_type' => ContentType::PinterestPin->value,
                    'meta' => ['board_id' => '123456789'],
                ],
            ],
        ]);

    $response->assertSessionDoesntHaveErrors(['platforms.0.meta.board_id']);
});

test('scheduling a pinterest post without board_id is rejected', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
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
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Scheduled->value,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'media' => $mediaPayload,
            'platforms' => [
                [
                    'id' => $pinterestPlatform->id,
                    'content_type' => ContentType::PinterestPin->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionHasErrors('platforms.0.meta.board_id');
});

test('publishing a pinterest carousel without board_id is rejected', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterestCarousel()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
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
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $mediaPayload,
            'platforms' => [
                [
                    'id' => $pinterestPlatform->id,
                    'content_type' => ContentType::PinterestCarousel->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionHasErrors('platforms.0.meta.board_id');
});

test('publishing a pinterest video pin without board_id is rejected', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterestVideoPin()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $this->mediaPayload,
            'platforms' => [
                [
                    'id' => $pinterestPlatform->id,
                    'content_type' => ContentType::PinterestVideoPin->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionHasErrors('platforms.0.meta.board_id');
});

test('pinterest board error does not block other platforms in multi-platform publish', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
        'meta' => [],
    ]);

    $linkedinAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    $linkedinPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $linkedinAccount->id,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
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
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'media' => $mediaPayload,
            'platforms' => [
                [
                    'id' => $pinterestPlatform->id,
                    'content_type' => ContentType::PinterestPin->value,
                    'meta' => [],
                ],
                [
                    'id' => $linkedinPlatform->id,
                    'content_type' => ContentType::LinkedInPost->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionHasErrors('platforms.0.meta.board_id');
    $response->assertSessionDoesntHaveErrors(['platforms.1.meta.board_id']);
});

test('saving a pinterest post as draft without board_id skips the board rule', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
        'meta' => [],
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'platforms' => [
                [
                    'id' => $pinterestPlatform->id,
                    'content_type' => ContentType::PinterestPin->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionDoesntHaveErrors(['platforms.0.meta.board_id']);
});

test('saving a tiktok post as draft without privacy_level skips the privacy_level rule', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'platforms' => [
                [
                    'id' => $this->postPlatform->id,
                    'content_type' => ContentType::TikTokVideo->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionDoesntHaveErrors(['platforms.0.meta.privacy_level']);
});

test('scheduling a threads post over 500 chars is rejected with the platform name', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $threadsPlatform = PostPlatform::factory()->threads()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $threadsAccount->id,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Scheduled->value,
            'content' => str_repeat('a', 537),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'platforms' => [
                [
                    'id' => $threadsPlatform->id,
                    'content_type' => ContentType::ThreadsPost->value,
                    'meta' => [],
                ],
            ],
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
    $threadsPlatform = PostPlatform::factory()->threads()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $threadsAccount->id,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Scheduled->value,
            'content' => str_repeat('a', 500),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'platforms' => [
                [
                    'id' => $threadsPlatform->id,
                    'content_type' => ContentType::ThreadsPost->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionDoesntHaveErrors('content');
});

test('saving an over-limit threads post as draft skips the content-length rule', function () {
    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $threadsPlatform = PostPlatform::factory()->threads()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $threadsAccount->id,
    ]);

    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'content' => str_repeat('a', 1000),
            'platforms' => [
                [
                    'id' => $threadsPlatform->id,
                    'content_type' => ContentType::ThreadsPost->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionDoesntHaveErrors('content');
});

test('scheduling across multiple platforms enforces the strictest content-length cap', function () {
    $facebookAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
    ]);
    $facebookPlatform = PostPlatform::factory()->facebook()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $facebookAccount->id,
    ]);

    $threadsAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
    ]);
    $threadsPlatform = PostPlatform::factory()->threads()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $threadsAccount->id,
    ]);

    // 600 chars: fine for Facebook (63206 cap), over for Threads (500 cap).
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Scheduled->value,
            'content' => str_repeat('a', 600),
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'platforms' => [
                [
                    'id' => $facebookPlatform->id,
                    'content_type' => ContentType::FacebookPost->value,
                    'meta' => [],
                ],
                [
                    'id' => $threadsPlatform->id,
                    'content_type' => ContentType::ThreadsPost->value,
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionHasErrors('content');
    expect(session('errors')->get('content')[0])->toContain('Threads');
});

test('draft save accepts media source metadata for ai regeneration', function () {
    $payload = [
        [
            'id' => 'media-ai-keep-meta',
            'path' => 'ai-images/generated.webp',
            'url' => 'https://example.com/ai-images/generated.webp',
            'type' => 'image',
            'mime_type' => 'image/webp',
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
            'platforms' => [],
        ]);

    $response->assertSessionDoesntHaveErrors();

    $this->post->refresh();
    expect(data_get($this->post->media, '0.source'))->toBe('ai');
    expect(data_get($this->post->media, '0.source_meta.title'))->toBe('Fix ECP typo');
});

test('instagram_carousel is rejected as a content_type — carousel is a feed post with multiple images', function () {
    $response = $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'platforms' => [
                [
                    'id' => $this->postPlatform->id,
                    'content_type' => 'instagram_carousel',
                    'meta' => [],
                ],
            ],
        ]);

    $response->assertSessionHasErrors('platforms.0.content_type');
});

test('publishing a discord post without a channel is rejected', function () {
    $account = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Discord,
        'content_type' => ContentType::DiscordMessage,
        'meta' => [],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Publishing->value,
            'platforms' => [[
                'id' => $postPlatform->id,
                'content_type' => ContentType::DiscordMessage->value,
                'meta' => [],
            ]],
        ])
        ->assertSessionHasErrors('platforms.0.meta.channel_id');
});

test('saving a discord draft without a channel is allowed', function () {
    $account = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Discord,
        'content_type' => ContentType::DiscordMessage,
        'meta' => [],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'platforms' => [[
                'id' => $postPlatform->id,
                'content_type' => ContentType::DiscordMessage->value,
                'meta' => [],
            ]],
        ])
        ->assertSessionDoesntHaveErrors('platforms.0.meta.channel_id');
});

test('saving a pinterest draft persists title and link meta', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
        'meta' => ['board_id' => 'board-1'],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'content' => 'Shared caption',
            'platforms' => [[
                'id' => $pinterestPlatform->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => [
                    'board_id' => 'board-1',
                    'title' => 'Pin Title',
                    'link' => 'https://example.com/product',
                ],
            ]],
        ])
        ->assertSessionDoesntHaveErrors();

    $meta = $pinterestPlatform->fresh()->meta;

    expect(data_get($meta, 'title'))->toBe('Pin Title')
        ->and(data_get($meta, 'link'))->toBe('https://example.com/product')
        ->and(array_key_exists('description', $meta))->toBeFalse();
});

test('clearing pinterest title and link removes the meta keys', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
        'meta' => [
            'board_id' => 'board-1',
            'title' => 'Keep me gone',
            'link' => 'https://example.com/gone',
        ],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'platforms' => [[
                'id' => $pinterestPlatform->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => [
                    'board_id' => 'board-1',
                    'title' => null,
                    'link' => null,
                ],
            ]],
        ])
        ->assertSessionDoesntHaveErrors();

    $meta = $pinterestPlatform->fresh()->meta;

    expect(data_get($meta, 'board_id'))->toBe('board-1')
        ->and(array_key_exists('title', $meta))->toBeFalse()
        ->and(array_key_exists('link', $meta))->toBeFalse();
});

test('pinterest rejects invalid link when scheduling', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
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
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Scheduled->value,
            'scheduled_at' => now()->addHour()->toIso8601String(),
            'content' => 'Ready to schedule',
            'media' => $mediaPayload,
            'platforms' => [[
                'id' => $pinterestPlatform->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => [
                    'board_id' => 'board-1',
                    'link' => 'not-a-url',
                ],
            ]],
        ])
        ->assertSessionHasErrors([
            'platforms.0.meta.link' => __('posts.form.pinterest.link_invalid'),
        ]);
});

test('pinterest meta title and link validation bounds are enforced', function () {
    $pinterestAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
    ]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $pinterestAccount->id,
        'meta' => [],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $this->post), [
            'status' => Status::Draft->value,
            'platforms' => [[
                'id' => $pinterestPlatform->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => [
                    'title' => str_repeat('t', 101),
                    'link' => 'not-a-url',
                ],
            ]],
        ])
        ->assertSessionHasErrors([
            'platforms.0.meta.title' => __('posts.form.pinterest.title_max'),
            'platforms.0.meta.link' => __('posts.form.pinterest.link_invalid'),
        ]);
});

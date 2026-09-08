<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\InstagramAnalytics;
use App\Services\Social\InstagramPublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->instagramFacebookAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'ig_fb_123',
        'username' => 'testuser',
        'display_name' => 'Test User',
        'access_token' => 'page_token_123',
        'refresh_token' => null,
        'token_expires_at' => null,
        'status' => AccountStatus::Connected,
        'is_active' => true,
        'meta' => [
            'page_id' => 'page_123',
            'page_name' => 'Test Page',
        ],
    ]);
});

test('instagram facebook platform uses graph.facebook.com base url', function () {
    expect(Platform::InstagramFacebook->instagramGraphBaseUrl())
        ->toContain('graph.facebook.com');

    expect(Platform::Instagram->instagramGraphBaseUrl())
        ->toContain('graph.instagram.com');
});

test('instagram facebook platform has correct label', function () {
    expect(Platform::InstagramFacebook->label())
        ->toBe('Instagram (Facebook Business)');
});

test('instagram facebook shares same content types as instagram', function () {
    expect(ContentType::defaultFor(Platform::InstagramFacebook))
        ->toBe(ContentType::InstagramFeed);
});

test('instagram facebook platform has correct media types', function () {
    $types = Platform::InstagramFacebook->allowedMediaTypes();

    expect($types)->toBe(Platform::Instagram->allowedMediaTypes());
});

test('instagram facebook platform has correct max content length', function () {
    expect(Platform::InstagramFacebook->maxContentLength())
        ->toBe(Platform::Instagram->maxContentLength());
});

test('instagram facebook platform has correct max images', function () {
    expect(Platform::InstagramFacebook->maxImages())
        ->toBe(Platform::Instagram->maxImages());
});

test('instagram facebook does not support text only', function () {
    expect(Platform::InstagramFacebook->supportsTextOnly())->toBeFalse();
});

test('instagram facebook has its own queue', function () {
    expect(Platform::InstagramFacebook->queue())
        ->toBe('social-instagram-facebook');
});

test('instagram facebook publisher uses graph.facebook.com', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Test post via Facebook Business',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->instagramFacebookAccount->id,
        'platform' => Platform::InstagramFacebook,
        'content_type' => ContentType::InstagramFeed,
    ]);

    $post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/test.jpg',
                'url' => 'https://example.com/media/test.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'id' => 'container_123',
            'status_code' => 'FINISHED',
            'permalink' => 'https://instagram.com/p/test123',
        ], 200),
    ]);

    $publisher = new InstagramPublisher;
    $result = $publisher->publish($postPlatform);

    expect($result)->toHaveKey('id');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'graph.facebook.com');
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'graph.instagram.com');
    });
});

test('instagram standalone publisher uses graph.instagram.com', function () {
    $standaloneAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig_standalone_123',
        'access_token' => 'ig_token_123',
        'token_expires_at' => now()->addDays(30),
        'status' => AccountStatus::Connected,
        'is_active' => true,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Test post via standalone',
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $standaloneAccount->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
    ]);

    $post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/test.jpg',
                'url' => 'https://example.com/media/test.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.instagram.com/*' => Http::response([
            'id' => 'container_456',
            'status_code' => 'FINISHED',
            'permalink' => 'https://instagram.com/p/test456',
        ], 200),
    ]);

    $publisher = new InstagramPublisher;
    $result = $publisher->publish($postPlatform);

    expect($result)->toHaveKey('id');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'graph.instagram.com');
    });

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'graph.facebook.com');
    });
});

test('instagram facebook does not refresh token', function () {
    // InstagramFacebook uses page tokens that don't expire
    expect($this->instagramFacebookAccount->refresh_token)->toBeNull();
    expect($this->instagramFacebookAccount->token_expires_at)->toBeNull();
});

test('analytics service supports instagram facebook', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response([
            'data' => [],
        ], 200),
    ]);

    $analytics = app(InstagramAnalytics::class);
    $metrics = $analytics->getMetrics($this->instagramFacebookAccount);

    expect($metrics)->toBeArray();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'graph.facebook.com');
    });
});

test('instagram facebook platform is included in all queues', function () {
    $queues = Platform::allQueues();
    expect($queues)->toContain('social-instagram-facebook');
});

test('instagram facebook is in supported analytics platforms', function () {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['data' => []], 200),
    ]);

    $analytics = app(InstagramAnalytics::class);
    $metrics = $analytics->getMetrics($this->instagramFacebookAccount);

    expect($metrics)->toBeArray();
});

<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\YouTubePublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'UC_channel_123',
        'username' => 'mychannel',
        'token_expires_at' => now()->addDays(7),
        'meta' => [
            'channel_id' => 'UC_channel_123',
            'google_user_id' => 'google_user_123',
        ],
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Check out this YouTube Short!',
    ]);

    $this->postPlatform = PostPlatform::factory()->youtube()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::YouTube,
        'content_type' => ContentType::YouTubeShort,
    ]);

    $this->publisher = new YouTubePublisher;
});

test('youtube publisher throws exception when no media', function () {
    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'YouTube Shorts requires a video to publish.');
});

test('youtube publisher throws exception for non-video content', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-image',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'YouTube Shorts only supports video content.');
});

test('youtube publisher refreshes token when expired', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test-video.mp4',
            ],
        ],
    ]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ], 200),
        '*' => Http::response(['error' => ['message' => 'Test']], 400),
    ]);

    try {
        $this->publisher->publish($this->postPlatform);
    } catch (Exception $e) {
        // Expected to fail on upload, but token should be refreshed
    }

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth2.googleapis.com/token');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('youtube publisher throws exception when no refresh token available', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => null,
    ]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test-video.mp4',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'No refresh token available for YouTube account');
});

test('youtube publisher throws exception on api init error', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test-video.mp4',
            ],
        ],
    ]);

    Http::fake([
        'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
            'error' => [
                'message' => 'Invalid request',
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

// Note: Testing token expiration on auth error would require mocking file_get_contents
// which is used to fetch video content. The token refresh test above covers the token
// expiration handling. Full integration tests should cover the 401 error scenario.

test('youtube publisher throws exception with null content', function () {
    $this->post->update([
        'content' => null,
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test.mp4',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'YouTube Shorts require a title');
});

test('youtube publisher resolves description from platform meta with content fallback', function () {
    $publisher = new YouTubePublisher;
    $reflection = new ReflectionClass($publisher);
    $method = $reflection->getMethod('resolveDescription');
    $method->setAccessible(true);

    // No meta: falls back to the post content (pre-meta behavior)
    $this->postPlatform->meta = null;
    expect($method->invoke($publisher, $this->postPlatform, 'Post content'))->toBe('Post content');

    // Meta description wins over the content
    $this->postPlatform->meta = ['description' => "Full description\nhttps://example.com/map"];
    expect($method->invoke($publisher, $this->postPlatform, 'Post content'))
        ->toBe("Full description\nhttps://example.com/map");

    // Whitespace-only meta description falls back to content
    $this->postPlatform->meta = ['description' => '   '];
    expect($method->invoke($publisher, $this->postPlatform, 'Post content'))->toBe('Post content');
});

test('youtube publisher builds correct title with shorts tag', function () {
    $publisher = new YouTubePublisher;
    $reflection = new ReflectionClass($publisher);
    $method = $reflection->getMethod('buildTitle');
    $method->setAccessible(true);

    // Short content: appends #Shorts
    $title = $method->invoke($publisher, 'My awesome short video');
    expect($title)->toBe('My awesome short video #Shorts');

    // Long content: truncates to leave room for #Shorts tag (100 chars max)
    $longContent = str_repeat('A', 200);
    $title = $method->invoke($publisher, $longContent);
    expect(strlen($title))->toBeLessThanOrEqual(100);
    expect($title)->toEndWith(' #Shorts');

    // Multi-line content: only uses first line before period
    $multiLine = "First sentence. Second part.\nSecond line";
    $title = $method->invoke($publisher, $multiLine);
    expect($title)->toBe('First sentence #Shorts');

    // Newline-separated: stops at newline
    $newlineContent = "Title line\nMore content here";
    $title = $method->invoke($publisher, $newlineContent);
    expect($title)->toBe('Title line #Shorts');
});

test('youtube publisher resolves title from platform meta with content fallback', function () {
    $publisher = new YouTubePublisher;
    $reflection = new ReflectionClass($publisher);
    $method = $reflection->getMethod('resolveTitle');
    $method->setAccessible(true);

    $this->postPlatform->meta = null;
    expect($method->invoke($publisher, $this->postPlatform, 'My awesome short video'))
        ->toBe('My awesome short video #Shorts');

    $this->postPlatform->meta = ['title' => 'Exact custom title'];
    expect($method->invoke($publisher, $this->postPlatform, 'My awesome short video'))
        ->toBe('Exact custom title');
});

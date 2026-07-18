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
use App\Services\Social\ThreadsPublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
        'token_expires_at' => now()->addDays(60),
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from Threads!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Threads,
        'content_type' => ContentType::ThreadsPost,
    ]);

    $this->publisher = new ThreadsPublisher;
});

test('threads publisher can publish text-only post', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('post-123456789');
    expect($result['url'])->toBe('https://www.threads.net/@testuser/post/ABC123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/threads')
            && str_contains($request->url(), '123456789');
    });
});

test('threads publisher can publish image post', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('post-123456789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/threads');
    });
});

test('threads publisher can publish video post', function () {
    $this->post->update([
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

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('post-123456789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/threads');
    });
});

test('threads publisher can publish carousel', function () {
    $mediaItems = [];
    for ($i = 0; $i < 3; $i++) {
        $mediaItems[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/test-image-{$i}.jpg",
            'url' => "https://example.com/media/2026-01/test-image-{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "test-{$i}.jpg",
        ];
    }
    $this->post->update([
        'media' => $mediaItems]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('post-123456789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/threads');
    });
});

test('threads publisher throws exception on api error', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'error' => [
                'message' => 'Invalid parameter',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('threads publisher throws token expired exception on auth error', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'error' => [
                'message' => 'Error validating access token',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('threads publisher refreshes token when expired', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        'https://graph.threads.net/refresh_access_token*' => Http::response([
            'access_token' => 'new-long-lived-token',
            'expires_in' => 5184000,
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'refresh_access_token');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-long-lived-token');
});

test('threads publisher throws TokenExpiredException when refresh_token is rejected', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        'https://graph.threads.net/refresh_access_token*' => Http::response([
            'error' => ['message' => 'Token is invalid', 'code' => 190],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'Token is invalid');
});

test('threads publisher waits for media processing', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::sequence()
            ->push(['status' => 'IN_PROGRESS'], 200)
            ->push(['status' => 'IN_PROGRESS'], 200)
            ->push(['status' => 'FINISHED'], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('post-123456789');
});

test('threads publisher handles media processing error', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'ERROR',
            'error_message' => 'Media upload failed',
        ], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Threads media processing failed');
});

test('threads publisher throws exception for text post with null content', function () {
    $this->post->update(['content' => null]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Threads text posts require content');
});

test('threads publisher can publish image with null content', function () {
    $this->post->update([
        'content' => null,
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'media-123',
        ], 200),
        'https://graph.threads.net/v1.0/media-123*' => Http::response([
            'permalink' => 'https://threads.net/@testuser/post/123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('media-123');
});

test('threads publisher throws exception when all carousel items fail', function () {
    $mediaItems = [];
    for ($i = 0; $i < 3; $i++) {
        $mediaItems[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/fail-image-{$i}.jpg",
            'url' => "https://example.com/media/2026-01/fail-image-{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "fail-{$i}.jpg",
        ];
    }
    $this->post->update([
        'media' => $mediaItems]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'error' => [
                'message' => 'Upload failed',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Failed to create any carousel items');
});

test('threads publisher sends capped alt text on single image container', function () {
    $longAlt = str_repeat('a', 1500);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
                'meta' => ['alt_text' => $longAlt],
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-alt-123',
        ], 200),
        'https://graph.threads.net/v1.0/post-alt-123*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ALT123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $expectedAlt = mb_substr($longAlt, 0, Platform::Threads->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        return str_ends_with($request->url(), '/123456789/threads')
            && data_get($request->data(), 'alt_text') === $expectedAlt
            && strlen($expectedAlt) === Platform::Threads->altTextMaxLength();
    });
});

test('threads publisher omits alt_text from single image container when no alt text is set', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-no-alt-123',
        ], 200),
        'https://graph.threads.net/v1.0/post-no-alt-123*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/NOALT123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/123456789/threads')
            && ! array_key_exists('alt_text', $request->data());
    });
});

test('threads publisher sends alt text on image carousel children but never on video children', function () {
    $imageAlt = str_repeat('x', 1500);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-image',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
                'meta' => ['alt_text' => $imageAlt],
            ],
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test.mp4',
                'meta' => ['alt_text' => 'video alt text must never be sent'],
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-carousel-alt-123',
        ], 200),
        'https://graph.threads.net/v1.0/post-carousel-alt-123*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/CAROUSELALT123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $expectedAlt = mb_substr($imageAlt, 0, Platform::Threads->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        $data = $request->data();

        return str_ends_with($request->url(), '/123456789/threads')
            && data_get($data, 'image_url') !== null
            && data_get($data, 'alt_text') === $expectedAlt;
    });

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_ends_with($request->url(), '/123456789/threads')
            && data_get($data, 'video_url') !== null
            && ! array_key_exists('alt_text', $data);
    });
});

test('threads publisher can publish video with null content', function () {
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

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'video-123',
        ], 200),
        'https://graph.threads.net/v1.0/video-123*' => Http::response([
            'permalink' => 'https://threads.net/@testuser/post/456',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('video-123');
});

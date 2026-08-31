<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\TikTokPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\TikTokPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'tiktok123',
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDays(1),
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Check out this TikTok video!',
    ]);

    $this->postPlatform = PostPlatform::factory()->tiktok()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::TikTok,
        'content_type' => ContentType::TikTokVideo,
    ]);

    $this->publisher = new TikTokPublisher;

    $this->api = config('trypost.platforms.tiktok.api');
});

test('tiktok publisher throws exception when no media', function () {
    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'TikTok requires media (video or photos) to publish.');
});

test('tiktok publisher can publish video', function () {
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
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publish_id' => 'pub_123',
            ],
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('pub_123');
    expect($result['url'])->toContain('tiktok.com/@tiktoker');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/post/publish/video/init/');
    });
});

test('tiktok publisher does not report success before processing completes', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'test-video.mp4',
        ]],
    ]);

    Http::fake([
        $this->api.'/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub_processing']]),
        $this->api.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PROCESSING_DOWNLOAD']]),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->context)->toBe(['tiktok_publish_id' => 'pub_processing'])
                ->and($exception->retryDelaySeconds)->toBe(30)
                ->and($exception->maxRetries)->toBe(120);
        });

    expect($this->postPlatform->fresh()->error_context['tiktok_publish_id'] ?? null)->toBe('pub_processing');

    Http::assertSentCount(2);
});

test('tiktok publisher checkpoints a video publish_id when status fetch reports an expired token', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'test-video.mp4',
        ]],
    ]);

    Http::fake([
        $this->api.'/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub_video_401']]),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'error' => [
                'code' => 'access_token_invalid',
                'message' => 'Access token is invalid',
            ],
        ], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);

    expect($this->postPlatform->fresh()->error_context['tiktok_publish_id'] ?? null)->toBe('pub_video_401');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/content/init/'));
});

test('tiktok publisher checkpoints a photo publish_id before polling status', function () {
    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [[
            'id' => 'test-media-image',
            'path' => 'media/2026-01/image1.jpg',
            'url' => 'https://example.com/media/2026-01/image1.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'image1.jpg',
            'meta' => ['width' => 1080, 'height' => 1080],
        ]],
    ]);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response(['data' => ['publish_id' => 'pub_photo_processing']]),
        $this->api.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PROCESSING_DOWNLOAD']]),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(PlatformUnavailableException::class);

    expect($this->postPlatform->fresh()->error_context['tiktok_publish_id'] ?? null)->toBe('pub_photo_processing');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/video/init/'));
});

test('tiktok publisher checkpoints photo derivatives with the publish_id before polling', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [[
            'id' => 'oversized',
            'path' => 'media/2026-01/big.jpg',
            'url' => 'https://example.com/media/2026-01/big.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'big.jpg',
            'meta' => ['width' => 1254, 'height' => 1254],
        ]],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    $mockOptimizer->shouldReceive('optimizeImage')->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response(['data' => ['publish_id' => 'pub_photo_deriv']]),
        $this->api.'/post/publish/status/fetch/' => Http::response(['data' => ['status' => 'PROCESSING_DOWNLOAD']]),
        '*' => Http::response('fake-image-content', 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(PlatformUnavailableException::class);

    $context = $this->postPlatform->fresh()->error_context;
    $paths = $context['tiktok_derivative_paths'] ?? null;

    expect($context['tiktok_publish_id'] ?? null)->toBe('pub_photo_deriv')
        ->and($paths)->toBeArray()
        ->and($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        Storage::assertExists($path);
    }
});

test('tiktok publisher keeps photo derivatives when status fetch reports an expired token', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [[
            'id' => 'oversized',
            'path' => 'media/2026-01/big.jpg',
            'url' => 'https://example.com/media/2026-01/big.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'big.jpg',
            'meta' => ['width' => 1254, 'height' => 1254],
        ]],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    $mockOptimizer->shouldReceive('optimizeImage')->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response(['data' => ['publish_id' => 'pub_photo_401']]),
        $this->api.'/post/publish/status/fetch/' => Http::sequence()
            ->push([
                'error' => [
                    'code' => 'access_token_invalid',
                    'message' => 'Access token is invalid',
                ],
            ], 401)
            ->push([
                'data' => [
                    'status' => 'PUBLISH_COMPLETE',
                    'publicaly_available_post_id' => ['video_123'],
                ],
            ]),
        '*' => Http::response('fake-image-content', 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);

    $context = $this->postPlatform->fresh()->error_context;
    $paths = $context['tiktok_derivative_paths'] ?? null;

    expect($context['tiktok_publish_id'] ?? null)->toBe('pub_photo_401')
        ->and($paths)->toBeArray()
        ->and($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        Storage::assertExists($path);
    }

    $result = $this->publisher->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('video_123');

    foreach ($paths as $path) {
        Storage::assertMissing($path);
    }

    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/post/publish/content/init/')))
        ->toHaveCount(1);
});

test('tiktok publisher prunes photo derivatives when TikTok confirms the publish failed', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [[
            'id' => 'oversized',
            'path' => 'media/2026-01/big.jpg',
            'url' => 'https://example.com/media/2026-01/big.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'big.jpg',
            'meta' => ['width' => 1254, 'height' => 1254],
        ]],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    $mockOptimizer->shouldReceive('optimizeImage')->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response(['data' => ['publish_id' => 'pub_photo_failed']]),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'FAILED',
                'fail_reason' => 'photo_pull_failed',
            ],
        ]),
        '*' => Http::response('fake-image-content', 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TikTokPublishException::class);

    expect($this->postPlatform->fresh()->error_context['tiktok_publish_id'] ?? null)->toBe('pub_photo_failed')
        ->and(Storage::allFiles('social-tiktok-photos'))->toBeEmpty();
});

test('tiktok publisher resumes an existing publish without creating a duplicate', function () {
    $this->postPlatform->update([
        'error_context' => ['tiktok_publish_id' => 'pub_existing'],
    ]);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => ['video_123'],
            ],
        ]),
    ]);

    $result = $this->publisher->publish($this->postPlatform->fresh());

    expect($result)->toBe([
        'id' => 'video_123',
        'url' => 'https://www.tiktok.com/@tiktoker/video/video_123',
    ]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/init/'));
});

test('tiktok publisher retries a server-error status fetch without creating a duplicate', function () {
    $this->postPlatform->update([
        'error_context' => ['tiktok_publish_id' => 'pub_existing'],
    ]);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response(['error' => ['code' => 'internal_error']], 503),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->httpStatus)->toBe(503)
                ->and($exception->context['tiktok_publish_id'] ?? null)->toBe('pub_existing');
        });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/init/'));
});

test('tiktok publisher retries a rate-limited status fetch without creating a duplicate', function () {
    $this->postPlatform->update([
        'error_context' => ['tiktok_publish_id' => 'pub_existing'],
    ]);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response(['error' => ['code' => 'rate_limit']], 429),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->httpStatus)->toBe(429)
                ->and($exception->context['tiktok_publish_id'] ?? null)->toBe('pub_existing');
        });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/init/'));
});

test('tiktok publisher keeps photo derivatives while pending and prunes them on completion', function () {
    Storage::fake();
    $derivativePath = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'image');

    $this->postPlatform->update([
        'error_context' => [
            'tiktok_publish_id' => 'pub_existing',
            'tiktok_derivative_paths' => [$derivativePath],
        ],
    ]);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::sequence()
            ->push(['data' => ['status' => 'PROCESSING_DOWNLOAD']])
            ->push(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['video_123']]]),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(function (PlatformUnavailableException $exception) use ($derivativePath): void {
            expect($exception->context['tiktok_derivative_paths'] ?? null)->toBe([$derivativePath]);
        });

    Storage::assertExists($derivativePath);

    $result = $this->publisher->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('video_123');
    Storage::assertMissing($derivativePath);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/init/'));
});

test('tiktok publisher can publish photos', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-image',
                'path' => 'media/2026-01/image1.jpg',
                'url' => 'https://example.com/media/2026-01/image1.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image1.jpg',
                'meta' => ['width' => 1080, 'height' => 1080],
            ],
        ],
    ]);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response([
            'data' => ['publish_id' => 'pub_photo_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publish_id' => 'pub_photo_123',
            ],
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result['id'])->toBe('pub_photo_123');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/content/init/')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return data_get($body, 'post_info.description') === 'Check out this TikTok video!'
            && ! isset($body['post_info']['title']);
    });
});

test('tiktok publisher throws exception on api error', function () {
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
        $this->api.'/post/publish/video/init/' => Http::response([
            'error' => [
                'code' => 'invalid_request',
                'message' => 'Invalid request',
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('tiktok publisher throws token expired exception on auth error', function () {
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
        $this->api.'/post/publish/video/init/' => Http::response([
            'error' => [
                'code' => 'access_token_invalid',
                'message' => 'Access token is invalid',
            ],
        ], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('tiktok publisher refreshes token when expired', function () {
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
        $this->api.'/oauth/token/' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 86400,
        ], 200),
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth/token');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('tiktok publisher throws exception when no refresh token available', function () {
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
        ->toThrow(TokenExpiredException::class, 'No refresh token available for TikTok account');
});

test('tiktok publisher throws TokenExpiredException when refresh_token is rejected', function () {
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
        $this->api.'/oauth/token/' => Http::response([
            'error' => ['code' => 'invalid_grant', 'message' => 'Refresh token expired'],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'Refresh token expired');
});

test('tiktok publisher throws exception for unsupported media type', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-doc',
                'path' => 'media/2026-01/doc.pdf',
                'url' => 'https://example.com/media/2026-01/doc.pdf',
                'mime_type' => 'application/pdf',
                'original_filename' => 'doc.pdf',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'TikTok only supports video or image content.');
});

test('tiktok publisher builds correct profile url when username present', function () {
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
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['url'])->toBe('https://www.tiktok.com/@tiktoker');
});

test('tiktok publisher returns null url when username missing', function () {
    $this->socialAccount->update(['username' => null]);

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
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['url'])->toBeNull();
});

test('tiktok publisher publishes with user-selected privacy level even when creator info query fails', function () {
    // User has explicitly selected SELF_ONLY in meta. creator_info failure must not block publishing.
    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);

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
        // creator_info/query returns 500 — should not affect publishing since user picked privacy_level.
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'error' => ['code' => 'internal_error', 'message' => 'Internal server error'],
        ], 500),
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_fallback_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publish_id' => 'pub_fallback_123',
            ],
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result['id'])->toBe('pub_fallback_123');

    // Assert SELF_ONLY (user's explicit pick) was used in the video init payload
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/video/init/')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return data_get($body, 'post_info.privacy_level') === 'SELF_ONLY';
    });
});

test('tiktok publisher throws exception when publish fails', function () {
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
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'FAILED',
                'fail_reason' => 'video_rejected',
            ],
        ], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TikTokPublishException::class);
});

test('tiktok publisher sends meta settings in video publish request', function () {
    $this->postPlatform->update([
        'meta' => [
            'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'allow_comments' => true,
            'allow_duet' => false,
            'allow_stitch' => true,
            'is_aigc' => true,
            'brand_content_toggle' => true,
            'brand_organic_toggle' => false,
        ],
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

    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
            ],
        ], 200),
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_meta_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/video/init/')) {
            return false;
        }
        $body = json_decode($request->body(), true);
        $postInfo = data_get($body, 'post_info');

        return $postInfo['privacy_level'] === 'PUBLIC_TO_EVERYONE'
            && $postInfo['disable_comment'] === false
            && $postInfo['disable_duet'] === true
            && $postInfo['disable_stitch'] === false
            && $postInfo['is_aigc'] === true
            && $postInfo['brand_content_toggle'] === true
            && ! isset($postInfo['brand_organic_toggle']);
    });
});

test('tiktok publisher sends auto_add_music for photo posts', function () {
    $this->postPlatform->update([
        'meta' => [
            'privacy_level' => 'SELF_ONLY',
            'allow_comments' => true,
            'auto_add_music' => true,
        ],
    ]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-photo',
                'path' => 'media/2026-01/photo.jpg',
                'url' => 'https://example.com/media/2026-01/photo.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'photo.jpg',
                'meta' => ['width' => 1080, 'height' => 1080],
            ],
        ],
    ]);

    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => ['privacy_level_options' => ['SELF_ONLY']],
        ], 200),
        $this->api.'/post/publish/content/init/' => Http::response([
            'data' => ['publish_id' => 'pub_music_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/content/init/')) {
            return false;
        }
        $body = json_decode($request->body(), true);
        $postInfo = data_get($body, 'post_info');

        return $postInfo['auto_add_music'] === true
            && ! isset($postInfo['disable_duet'])
            && ! isset($postInfo['disable_stitch'])
            && ! isset($postInfo['is_aigc'])
            && ! isset($postInfo['title'])
            && isset($postInfo['description']);
    });
});

test('tiktok publisher does not send auto_add_music for video posts', function () {
    $this->postPlatform->update([
        'meta' => [
            'privacy_level' => 'SELF_ONLY',
            'auto_add_music' => true,
        ],
    ]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/video.mp4',
                'url' => 'https://example.com/media/2026-01/video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'video.mp4',
            ],
        ],
    ]);

    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => ['privacy_level_options' => ['SELF_ONLY']],
        ], 200),
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_vid_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/video/init/')) {
            return false;
        }
        $body = json_decode($request->body(), true);
        $postInfo = data_get($body, 'post_info');

        return ! isset($postInfo['auto_add_music']);
    });
});

test('tiktok publisher uses default settings when only privacy_level is set', function () {
    // Only privacy_level is set (required); all other meta keys absent — exercise default toggles.
    $this->postPlatform->update(['meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE']]);

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
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'],
            ],
        ], 200),
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_default_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/video/init/')) {
            return false;
        }
        $body = json_decode($request->body(), true);
        $postInfo = data_get($body, 'post_info');

        // privacy_level passes through; all interaction toggles default to OFF to match
        // the UI checkbox state (TikTok UX guideline: none should be checked by default).
        return $postInfo['privacy_level'] === 'PUBLIC_TO_EVERYONE'
            && $postInfo['disable_comment'] === true
            && $postInfo['disable_duet'] === true
            && $postInfo['disable_stitch'] === true
            && ! isset($postInfo['is_aigc'])
            && ! isset($postInfo['brand_content_toggle']);
    });
});

test('tiktok publisher sends video caption in title field, never description', function () {
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
        'content' => 'My video caption',
    ]);
    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);

    Http::fake([
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'privacy_level_options' => ['SELF_ONLY'],
            ],
        ], 200),
        $this->api.'/post/publish/video/init/' => Http::response([
            'data' => ['publish_id' => 'pub_video_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE', 'publish_id' => 'pub_video_123'],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/video/init/')) {
            return false;
        }
        $body = json_decode($request->body(), true);

        return data_get($body, 'post_info.title') === 'My video caption'
            && ! isset($body['post_info']['description']);
    });
});

test('tiktok publisher throws when meta.privacy_level is missing and user did not pick', function () {
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
    // Explicitly clear privacy_level from meta (simulate UI never set it).
    $this->postPlatform->update(['meta' => []]);

    Http::fake([
        // creator_info returns a healthy response — fallback would have silently picked PUBLIC_TO_EVERYONE.
        $this->api.'/post/publish/creator_info/query/' => Http::response([
            'data' => [
                'creator_nickname' => 'test',
                'creator_username' => 'test',
                'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
                'comment_disabled' => false,
                'duet_disabled' => false,
                'stitch_disabled' => false,
                'max_video_post_duration_sec' => 300,
            ],
        ], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TikTokPublishException::class);
});

test('tiktok publisher resizes an oversized photo and pulls a hosted compliant copy', function () {
    Storage::fake();

    // TikTok rejects images wider than 1080px; this one is 1254px wide.
    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-oversized',
                'path' => 'media/2026-01/big.jpg',
                'url' => 'https://example.com/media/2026-01/big.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'big.jpg',
                'meta' => ['width' => 1254, 'height' => 1254],
            ],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    $mockOptimizer->shouldReceive('optimizeImage')->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response([
            'data' => ['publish_id' => 'pub_resize_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    // TikTok must be handed the hosted derivative, never the oversized original.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/content/init/')) {
            return false;
        }
        $photoUrl = data_get(json_decode($request->body(), true), 'source_info.photo_images.0');

        return str_contains($photoUrl, 'social-tiktok-photos/')
            && ! str_contains($photoUrl, 'example.com');
    });

    // The derivative is pruned once TikTok has pulled it.
    expect(Storage::allFiles('social-tiktok-photos'))->toBeEmpty();
});

test('tiktok publisher passes a compliant photo through without hosting a copy', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-compliant',
                'path' => 'media/2026-01/ok.jpg',
                'url' => 'https://example.com/media/2026-01/ok.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'ok.jpg',
                'meta' => ['width' => 1080, 'height' => 1920],
            ],
        ],
    ]);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response([
            'data' => ['publish_id' => 'pub_passthrough_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    // The original URL is published unchanged and nothing is downloaded or hosted.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/content/init/')) {
            return false;
        }

        return data_get(json_decode($request->body(), true), 'source_info.photo_images.0')
            === 'https://example.com/media/2026-01/ok.jpg';
    });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'example.com'));
    expect(Storage::allFiles('social-tiktok-photos'))->toBeEmpty();
});

test('tiktok publisher resizes a photo when its dimensions are unknown', function () {
    Storage::fake();

    // No width/height metadata: fall back to the safe path and host a compliant copy.
    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-unknown',
                'path' => 'media/2026-01/unknown.jpg',
                'url' => 'https://example.com/media/2026-01/unknown.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'unknown.jpg',
            ],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    $mockOptimizer->shouldReceive('optimizeImage')->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response([
            'data' => ['publish_id' => 'pub_unknown_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/content/init/')) {
            return false;
        }

        return str_contains(
            (string) data_get(json_decode($request->body(), true), 'source_info.photo_images.0'),
            'social-tiktok-photos/'
        );
    });

    expect(Storage::allFiles('social-tiktok-photos'))->toBeEmpty();
});

test('tiktok publisher fails clearly when an oversized photo cannot be downloaded for resizing', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-oversized',
                'path' => 'media/2026-01/big.jpg',
                'url' => 'https://example.com/media/2026-01/big.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'big.jpg',
                'meta' => ['width' => 1254, 'height' => 1254],
            ],
        ],
    ]);

    // optimizeImage is intentionally not stubbed: it must never be reached when the
    // download fails, and the strict mock would throw if it were called.
    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        '*' => Http::response('not found', 500),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TikTokPublishException::class, 'Failed to download image for TikTok resizing');

    // Nothing should be left hosted when resizing never completes.
    expect(Storage::allFiles('social-tiktok-photos'))->toBeEmpty();
});

test('tiktok publisher resizes only the oversized photos in a mixed carousel', function () {
    Storage::fake();

    // TikTok carousels can carry many images; here one is oversized, one is compliant.
    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [
            [
                'id' => 'oversized',
                'path' => 'media/2026-01/big.jpg',
                'url' => 'https://example.com/media/2026-01/big.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'big.jpg',
                'meta' => ['width' => 1254, 'height' => 1254],
            ],
            [
                'id' => 'compliant',
                'path' => 'media/2026-01/small.jpg',
                'url' => 'https://example.com/media/2026-01/small.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'small.jpg',
                'meta' => ['width' => 1080, 'height' => 1080],
            ],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    // optimizeImage must run exactly once — only for the oversized image.
    $mockOptimizer->shouldReceive('optimizeImage')->once()->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response([
            'data' => ['publish_id' => 'pub_mixed_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    // Order is preserved: oversized -> hosted derivative, compliant -> original URL untouched.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/post/publish/content/init/')) {
            return false;
        }
        $images = data_get(json_decode($request->body(), true), 'source_info.photo_images');

        return is_array($images)
            && count($images) === 2
            && str_contains($images[0], 'social-tiktok-photos/')
            && ! str_contains($images[0], 'example.com')
            && $images[1] === 'https://example.com/media/2026-01/small.jpg';
    });

    // The compliant image is never downloaded; only the oversized one is fetched for resizing.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'small.jpg'));

    // The single derivative is pruned after publish.
    expect(Storage::allFiles('social-tiktok-photos'))->toBeEmpty();
});

test('tiktok publisher prunes the hosted derivative even when publishing fails', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [
            [
                'id' => 'oversized',
                'path' => 'media/2026-01/big.jpg',
                'url' => 'https://example.com/media/2026-01/big.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'big.jpg',
                'meta' => ['width' => 1254, 'height' => 1254],
            ],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    $mockOptimizer->shouldReceive('optimizeImage')->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    // The derivative is hosted first, then TikTok rejects the publish: the finally
    // must still remove it so a failed post never orphans a file on disk.
    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response([
            'error' => ['code' => 'internal_error', 'message' => 'boom'],
        ], 500),
        '*' => Http::response('fake-image-content', 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TikTokPublishException::class);

    expect(Storage::allFiles('social-tiktok-photos'))->toBeEmpty();
});

test('tiktok publisher still reports success when derivative cleanup throws on the storage disk', function () {
    // The production default disk (r2) is configured to throw on a failed delete.
    // Cleanup must never turn an already-published post into a reported failure.
    $this->postPlatform->update(['meta' => ['privacy_level' => 'SELF_ONLY']]);
    $this->post->update([
        'media' => [
            [
                'id' => 'oversized',
                'path' => 'media/2026-01/big.jpg',
                'url' => 'https://example.com/media/2026-01/big.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'big.jpg',
                'meta' => ['width' => 1254, 'height' => 1254],
            ],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('maxWidthForPlatform')->with(Platform::TikTok)->andReturn(1080);
    $mockOptimizer->shouldReceive('optimizeImage')->with(Mockery::type('string'), Platform::TikTok)->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'tt_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Storage::shouldReceive('put')->andReturnTrue();
    Storage::shouldReceive('url')->andReturn('https://cdn.example.com/social-tiktok-photos/x.jpg');
    Storage::shouldReceive('delete')->andThrow(new RuntimeException('r2 delete failed'));

    Http::fake([
        $this->api.'/post/publish/content/init/' => Http::response([
            'data' => ['publish_id' => 'pub_cleanup_throws_123'],
        ], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE'],
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('pub_cleanup_throws_123');
});

test('tiktok publisher keeps links intact', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    $this->post->update([
        'content' => 'New post: https://acme.com/blog',
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'test-video.mp4',
        ]],
    ]);

    Http::fake([
        $this->api.'/post/publish/video/init/' => Http::response(['data' => ['publish_id' => 'pub_123']], 200),
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE', 'publish_id' => 'pub_123'],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/video/init/')
        && data_get($request->data(), 'post_info.title') === 'New post: https://acme.com/blog');
});

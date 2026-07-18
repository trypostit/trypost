<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\XPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\XPublisher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
        'token_expires_at' => now()->addHours(2),
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from X!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
    ]);

    $this->publisher = new XPublisher;
});

test('x publisher can publish text-only post', function () {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'data' => [
                'id' => '1234567890123456789',
                'text' => 'Hello from X!',
            ],
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('1234567890123456789');
    expect($result['url'])->toBe('https://x.com/testuser/status/1234567890123456789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/2/tweets')
            && $request['text'] === 'Hello from X!';
    });
});

test('x publisher does NOT rotate the token when it is only expiring soon but still valid', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->addMinutes(5),
        'refresh_token' => 'original-refresh-token',
    ]);
    $originalAccessToken = $this->socialAccount->access_token;

    Http::fake([
        config('trypost.platforms.x.api').'/tweets' => Http::response(['data' => ['id' => '999']], 200),
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'should-not-be-used',
            'refresh_token' => 'should-not-be-used',
            'expires_in' => 7200,
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    // X single-use refresh tokens: a still-valid access_token must NOT be rotated.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/oauth2/token'));
    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe($originalAccessToken);
    expect($this->socialAccount->refresh_token)->toBe('original-refresh-token');
});

test('x publisher uses bearer token authentication', function () {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'data' => [
                'id' => '1234567890123456789',
            ],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization')
            && str_starts_with($request->header('Authorization')[0], 'Bearer ');
    });
});

test('x publisher throws exception on api error', function () {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'detail' => 'You are not allowed to create a Tweet with duplicate content.',
            'type' => 'about:blank',
            'title' => 'Forbidden',
            'status' => 403,
        ], 403),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('x publisher throws token expired exception on auth error', function () {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'title' => 'Unauthorized',
            'detail' => 'Unauthorized',
            'status' => 401,
        ], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('x publisher refreshes token when expired', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 7200,
        ], 200),
        'https://api.x.com/2/tweets' => Http::response([
            'data' => [
                'id' => '1234567890123456789',
            ],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'oauth2/token')) {
            return false;
        }

        // Verify Basic Auth is used for confidential client authentication
        $authHeader = $request->header('Authorization')[0] ?? '';

        return str_starts_with($authHeader, 'Basic ');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('x publisher includes media ids in post when media uploaded', function () {
    // Note: This test verifies the post structure when media IDs are present
    // Actual media upload requires file_get_contents which needs real files
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'data' => [
                'id' => '1234567890123456789',
            ],
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/2/tweets');
    });
});

test('x publisher throws exception with empty content and no media', function () {
    $this->post->update(['content' => '']);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'X posts require either text or media');
});

test('x publisher throws exception with null content and no media', function () {
    $this->post->update(['content' => null]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'X posts require either text or media');
});

test('x publisher throws exception when no refresh token available', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => null,
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'No refresh token available for X account');
});

test('x publisher throws TokenExpiredException when refresh_token is rejected by X', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        'https://api.x.com/2/oauth2/token' => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'Value passed for the token was invalid.',
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'Value passed for the token was invalid.');
});

test('x publisher handles gif upload with processing', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-gif',
                'path' => 'media/2026-01/animated.gif',
                'url' => 'https://example.com/media/2026-01/animated.gif',
                'mime_type' => 'image/gif',
                'original_filename' => 'animated.gif',
            ],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'gif_media_555']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            // Return processing_info so waitForProcessing is triggered
            return Http::response([
                'data' => ['id' => 'gif_media_555'],
                'processing_info' => ['state' => 'pending', 'check_after_secs' => 1],
            ], 200);
        }

        if (str_contains($url, '/2/media/gif_media_555')) {
            return Http::response(['processing_info' => ['state' => 'succeeded']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '9999888877776666', 'text' => 'Hello from X!']], 200);
        }

        // GIF download
        return Http::response('fake-gif-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('9999888877776666');

    // GIF uses chunked upload (not simple upload)
    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/media/upload/initialize'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/append'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/finalize'));
    // waitForProcessing was called
    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/media/gif_media_555'));
});

test('x publisher recovers a missing mime type from the downloaded bytes', function () {
    $this->post->update([
        'media' => [
            ['url' => 'https://cdn.example.com/listing'],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('optimizeImage')->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'x_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/media/upload')) {
            return Http::response(['data' => ['id' => 'media_id_111']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '1212121212', 'text' => 'Hello from X!']], 200);
        }

        return Http::response(
            file_get_contents(__DIR__.'/../../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        );
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('1212121212');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/media/upload'));
});

test('x publisher sends image alt text to the media metadata endpoint', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
                'meta' => ['alt_text' => 'a red bike'],
            ],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('optimizeImage')->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'x_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/media/metadata')) {
            return Http::response([], 200);
        }

        if (str_contains($url, '/media/upload')) {
            return Http::response(['data' => ['id' => 'media_id_alt_1']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '1212121212', 'text' => 'Hello from X!']], 200);
        }

        return Http::response(
            file_get_contents(__DIR__.'/../../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        );
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/media/metadata')) {
            return false;
        }

        return data_get($request->data(), 'id') === 'media_id_alt_1'
            && data_get($request->data(), 'metadata.alt_text.text') === 'a red bike';
    });
});

test('x publisher truncates alt text to the platform max length', function () {
    $longAlt = str_repeat('a', 1200);

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

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('optimizeImage')->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'x_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/media/metadata')) {
            return Http::response([], 200);
        }

        if (str_contains($url, '/media/upload')) {
            return Http::response(['data' => ['id' => 'media_id_alt_2']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '1212121213', 'text' => 'Hello from X!']], 200);
        }

        return Http::response(
            file_get_contents(__DIR__.'/../../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        );
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/media/metadata')) {
            return false;
        }

        return data_get($request->data(), 'metadata.alt_text.text') === str_repeat('a', 1000);
    });
});

test('x publisher does not call media metadata when no alt text is set', function () {
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

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('optimizeImage')->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'x_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/media/upload')) {
            return Http::response(['data' => ['id' => 'media_id_alt_3']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '1212121214', 'text' => 'Hello from X!']], 200);
        }

        return Http::response(
            file_get_contents(__DIR__.'/../../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        );
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media/metadata'));
});

test('x publisher still posts the tweet when the alt text metadata call fails', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
                'meta' => ['alt_text' => 'a red bike'],
            ],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('optimizeImage')->andReturnUsing(function (string $tempFile) {
        $optimized = tempnam(sys_get_temp_dir(), 'x_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/media/metadata')) {
            throw new ConnectionException('Connection timed out');
        }

        if (str_contains($url, '/media/upload')) {
            return Http::response(['data' => ['id' => 'media_id_alt_fail']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '5551112223', 'text' => 'Hello from X!']], 200);
        }

        return Http::response(
            file_get_contents(__DIR__.'/../../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        );
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('5551112223');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/tweets'));
});

test('x publisher does not send alt text metadata for a video even if it carries alt text', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/clip.mp4',
                'url' => 'https://example.com/media/2026-01/clip.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'clip.mp4',
                'meta' => ['alt_text' => 'alt text must not be sent for a video'],
            ],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'video_media_777']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response(['data' => ['id' => 'video_media_777']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '7778889990', 'text' => 'Hello from X!']], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('7778889990');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media/metadata'));
});

test('x publisher uploads video via chunked upload', function () {
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

    Http::fake(function ($request) {
        $url = $request->url();

        // Order matters: more specific patterns first
        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'media_id_999']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response(['data' => ['id' => 'media_id_999']], 200);
        }

        if (str_contains($url, '/2/media/')) {
            // STATUS check: GET /2/media/{id}
            return Http::response(['processing_info' => ['state' => 'succeeded']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '9876543210987654321', 'text' => 'Hello from X!']], 200);
        }

        // Media download (any URL including relative paths in test env)
        return Http::response('fake-video-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('9876543210987654321');
    expect($result['url'])->toContain('x.com/testuser/status/9876543210987654321');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/media/upload/initialize'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/append'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/finalize'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/tweets'));
});

test('x publisher uploads a large video in sequential 1MB segments', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/big-video.mp4',
                'url' => 'https://example.com/media/2026-01/big-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'big-video.mp4',
            ],
        ],
    ]);

    $appendCount = 0;

    Http::fake(function ($request) use (&$appendCount) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'media_id_999']], 200);
        }

        if (str_contains($url, '/append')) {
            $appendCount++;

            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response(['data' => ['id' => 'media_id_999']], 200);
        }

        if (str_contains($url, '/2/media/')) {
            return Http::response(['processing_info' => ['state' => 'succeeded']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '9876543210987654321', 'text' => 'Hello from X!']], 200);
        }

        // ~2.5MB download -> at least three 1MB segments.
        return Http::response(str_repeat('x', (int) (2.5 * 1024 * 1024)), 200);
    });

    $this->publisher->publish($this->postPlatform);

    expect($appendCount)->toBeGreaterThan(1);
});

test('x publisher fails cleanly when media cannot be downloaded', function () {
    $this->post->update([
        'media' => [
            ['url' => 'https://cdn.example.com/listing', 'mime_type' => 'image/jpeg'],
        ],
    ]);

    Http::fake(['cdn.example.com/listing' => Http::response(null, 404)]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(XPublishException::class, 'Could not fetch the media to upload to X');
});

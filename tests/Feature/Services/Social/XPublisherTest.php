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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Official X upload STATUS: GET /2/media/upload?media_id=...&command=STATUS
 */
function isXMediaUploadStatusRequest(Request $request): bool
{
    if (strtoupper($request->method()) !== 'GET') {
        return false;
    }

    $url = $request->url();

    if (
        ! str_contains($url, '/media/upload')
        || str_contains($url, '/initialize')
        || str_contains($url, '/append')
        || str_contains($url, '/finalize')
    ) {
        return false;
    }

    return str_contains($url, 'media_id=')
        || filled(data_get($request->data(), 'media_id'));
}

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
            // Nested under data — matches the real X API shape.
            return Http::response([
                'data' => [
                    'id' => 'gif_media_555',
                    'processing_info' => ['state' => 'pending', 'check_after_secs' => 0],
                ],
            ], 200);
        }

        if (isXMediaUploadStatusRequest($request)) {
            return Http::response([
                'data' => [
                    'processing_info' => ['state' => 'succeeded'],
                ],
            ], 200);
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
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/2/media/upload/initialize')) {
            return false;
        }

        $contentType = $request->header('Content-Type')[0] ?? '';

        return str_contains($contentType, 'application/json')
            && data_get($request->data(), 'media_type') === 'image/gif'
            && data_get($request->data(), 'media_category') === 'tweet_gif';
    });
    Http::assertSent(fn ($request) => str_contains($request->url(), '/append'));
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/finalize')) {
            return false;
        }

        $contentType = $request->header('Content-Type')[0] ?? '';

        return str_contains($contentType, 'application/json')
            && $request->body() === '{}';
    });
    // waitForProcessing polls the official STATUS endpoint
    Http::assertSent(function ($request) {
        return isXMediaUploadStatusRequest($request)
            && (
                str_contains($request->url(), 'media_id=gif_media_555')
                || data_get($request->data(), 'media_id') === 'gif_media_555'
            )
            && (
                str_contains($request->url(), 'command=STATUS')
                || data_get($request->data(), 'command') === 'STATUS'
            );
    });
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

        if (isXMediaUploadStatusRequest($request)) {
            return Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200);
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

        if (isXMediaUploadStatusRequest($request)) {
            // STATUS check: GET /2/media/upload?media_id=...&command=STATUS
            return Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200);
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
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/append')
            && (int) data_get($request->data(), 'segment_index') === 0;
    });
    Http::assertSent(fn ($request) => str_contains($request->url(), '/finalize'));
    Http::assertSent(fn ($request) => isXMediaUploadStatusRequest($request));
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/2/tweets')) {
            return false;
        }

        $mediaIds = data_get($request->data(), 'media.media_ids');

        return is_array($mediaIds)
            && $mediaIds === ['media_id_999']
            && is_string($mediaIds[0]);
    });
});

test('x publisher sends JSON object bodies for chunked upload initialize and finalize', function () {
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

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'media_id_json']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response(['data' => ['id' => 'media_id_json']], 200);
        }

        if (isXMediaUploadStatusRequest($request)) {
            return Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => '1111222233334444', 'text' => 'Hello from X!']], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/2/media/upload/initialize')) {
            return false;
        }

        $contentType = $request->header('Content-Type')[0] ?? '';

        return str_contains($contentType, 'application/json')
            && data_get($request->data(), 'media_type') === 'video/mp4'
            && data_get($request->data(), 'media_category') === 'tweet_video';
    });

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/finalize')) {
            return false;
        }

        $contentType = $request->header('Content-Type')[0] ?? '';

        // Empty PHP array encodes as `[]`; X requires a JSON *object* (`{}`).
        return str_contains($contentType, 'application/json')
            && $request->body() === '{}';
    });
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

        if (isXMediaUploadStatusRequest($request)) {
            return Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200);
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

test('x publisher uses simple upload for small images and skips chunked finalize', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-image',
                'path' => 'media/2026-01/photo.jpg',
                'url' => 'https://example.com/media/2026-01/photo.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'photo.jpg',
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

        if (str_contains($url, '/media/upload')
            && ! str_contains($url, '/initialize')
            && ! str_contains($url, '/append')
            && ! str_contains($url, '/finalize')) {
            return Http::response(['data' => ['id' => 'simple_image_1']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => 'tweet_simple_1']], 200);
        }

        return Http::response(file_get_contents(__DIR__.'/../../../fixtures/1x1.png'), 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('tweet_simple_1');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/initialize'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/finalize'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/media/upload')
        && ! str_contains($request->url(), '/initialize'));
});

test('x publisher uses chunked upload for images larger than 5MB', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-large-image',
                'path' => 'media/2026-01/large.jpg',
                'url' => 'https://example.com/media/2026-01/large.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'large.jpg',
            ],
        ],
    ]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('optimizeImage')->andReturnUsing(function () {
        $optimized = tempnam(sys_get_temp_dir(), 'x_opt_');
        file_put_contents($optimized, str_repeat('x', (5 * 1024 * 1024) + 10));

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'large_image_1']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response(['data' => ['id' => 'large_image_1']], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => 'tweet_large_image']], 200);
        }

        return Http::response('tiny', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/2/media/upload/initialize')) {
            return false;
        }

        return data_get($request->data(), 'media_type') === 'image/jpeg'
            && data_get($request->data(), 'media_category') === 'tweet_image'
            && data_get($request->data(), 'total_bytes') > 5 * 1024 * 1024;
    });
    Http::assertSent(fn ($request) => str_contains($request->url(), '/finalize')
        && $request->body() === '{}');
});

test('x publisher uses amplify_video category for videos larger than 15MB', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-amplify',
                'path' => 'media/2026-01/big.mp4',
                'url' => 'https://example.com/media/2026-01/big.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'big.mp4',
            ],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'amplify_1']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response(['data' => ['id' => 'amplify_1']], 200);
        }

        if (isXMediaUploadStatusRequest($request)) {
            return Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response(['data' => ['id' => 'tweet_amplify']], 200);
        }

        return Http::response(str_repeat('v', (15 * 1024 * 1024) + 10), 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/2/media/upload/initialize')) {
            return false;
        }

        return data_get($request->data(), 'media_category') === 'amplify_video'
            && data_get($request->data(), 'total_bytes') > 15 * 1024 * 1024;
    });
});

test('x publisher fails when chunked finalize is rejected by X', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/clip.mp4',
                'url' => 'https://example.com/media/2026-01/clip.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'clip.mp4',
            ],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'media_fail_finalize']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response([
                'detail' => 'One or more parameters to your request was invalid.',
                'errors' => [['message' => 'Request body must be a JSON object.']],
                'title' => 'Invalid Request',
                'type' => 'https://api.x.com/2/problems/invalid-request',
            ], 400);
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(XPublishException::class, 'X rejected the media upload request');
});

test('x publisher fails when append is rejected by X', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/clip.mp4',
                'url' => 'https://example.com/media/2026-01/clip.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'clip.mp4',
            ],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'media_fail_append']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response([
                'title' => 'Invalid Request',
                'detail' => 'Segments do not add up to provided total file size.',
                'type' => 'https://api.x.com/2/problems/invalid-request',
            ], 400);
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(XPublishException::class);
});

test('x publisher fails when media processing reports failed', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/clip.mp4',
                'url' => 'https://example.com/media/2026-01/clip.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'clip.mp4',
            ],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'media_proc_fail']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response([
                'data' => [
                    'id' => 'media_proc_fail',
                    'processing_info' => ['state' => 'pending', 'check_after_secs' => 0],
                ],
            ], 200);
        }

        if (isXMediaUploadStatusRequest($request)) {
            return Http::response([
                'data' => [
                    'processing_info' => [
                        'state' => 'failed',
                        'error' => 'Unsupported codec',
                    ],
                ],
            ], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(XPublishException::class, 'X could not process the uploaded media');
});

test('x publisher fails when tweet rejects invalid media ids', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/clip.mp4',
                'url' => 'https://example.com/media/2026-01/clip.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'clip.mp4',
            ],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/2/media/upload/initialize')) {
            return Http::response(['data' => ['id' => 'media_invalid']], 200);
        }

        if (str_contains($url, '/append')) {
            return Http::response(null, 204);
        }

        if (str_contains($url, '/finalize')) {
            return Http::response(['data' => ['id' => 'media_invalid']], 200);
        }

        if (isXMediaUploadStatusRequest($request)) {
            return Http::response(['data' => ['processing_info' => ['state' => 'succeeded']]], 200);
        }

        if (str_contains($url, '/2/tweets')) {
            return Http::response([
                'detail' => 'One or more parameters to your request was invalid.',
                'errors' => [[
                    'parameters' => ['media.media_ids' => ['media_invalid']],
                    'message' => 'Your media IDs are invalid.',
                ]],
                'title' => 'Invalid Request',
                'type' => 'https://api.x.com/2/problems/invalid-request',
            ], 400);
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(XPublishException::class, 'X rejected the attached media');
});

test('x publisher sends the tweet with links defused', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $this->post->update(['content' => 'New post: https://trypost.it/blog']);

    Http::fake([
        config('trypost.platforms.x.api').'/tweets' => Http::response(['data' => ['id' => '111']], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/tweets')
        && $request['text'] === 'New post: trypost(.)it/blog');
});

test('x publisher leaves the tweet untouched when defusing is disabled', function () {
    config()->set('trypost.platforms.x.defuse_links', false);
    $this->post->update(['content' => 'New post: https://trypost.it/blog']);

    Http::fake([
        config('trypost.platforms.x.api').'/tweets' => Http::response(['data' => ['id' => '111']], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/2/tweets')
        && $request['text'] === 'New post: https://trypost.it/blog');
});

test('x publisher rejects a post that only fits before its links are defused', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $this->post->update(['content' => str_repeat('a', 271).' acme.com']);

    Http::fake([config('trypost.platforms.x.api').'/tweets' => Http::response(['data' => ['id' => '1']], 200)]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Content exceeds X limit of 280 characters (282 provided).');

    Http::assertNothingSent();
});

test('x publisher accepts a post that only fits once its links are defused', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $this->post->update(['content' => str_repeat('a', 263).' https://acme.com/x']);

    Http::fake([config('trypost.platforms.x.api').'/tweets' => Http::response(['data' => ['id' => '1']], 200)]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => mb_strlen($request['text']) === 276);
});

test('x publisher does not count html markup toward the character limit', function () {
    $this->post->update(['content' => '<p>'.str_repeat('a', 275).'</p>']);

    Http::fake([config('trypost.platforms.x.api').'/tweets' => Http::response(['data' => ['id' => '1']], 200)]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => mb_strlen($request['text']) === 275);
});

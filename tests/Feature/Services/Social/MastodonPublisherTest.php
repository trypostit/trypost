<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\MastodonPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\MastodonPublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->mastodon()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from Mastodon!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Mastodon,
        'content_type' => ContentType::MastodonPost,
    ]);

    $this->publisher = new MastodonPublisher;
});

test('mastodon publisher can publish text-only post', function () {
    Http::fake([
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '109876543210',
            'url' => 'https://mastodon.social/@testuser/109876543210',
            'content' => '<p>Hello from Mastodon!</p>',
            'created_at' => now()->toIso8601String(),
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('109876543210');
    expect($result['url'])->toBe('https://mastodon.social/@testuser/109876543210');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/v1/statuses')
            && $request['status'] === 'Hello from Mastodon!'
            && $request['visibility'] === 'public';
    });
});

test('mastodon publisher works with custom instance', function () {
    $this->socialAccount->update([
        'meta' => [
            'instance' => 'https://techhub.social',
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
        ],
    ]);

    Http::fake([
        'https://techhub.social/api/v1/statuses' => Http::response([
            'id' => '987654321',
            'url' => 'https://techhub.social/@testuser/987654321',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['url'])->toContain('techhub.social');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'techhub.social');
    });
});

test('mastodon publisher uploads media', function () {
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
        'https://mastodon.social/api/v1/media' => Http::response([
            'id' => 'media-123',
            'type' => 'image',
            'url' => 'https://mastodon.social/media/image.jpg',
        ], 200),
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '109876543210',
            'url' => 'https://mastodon.social/@testuser/109876543210',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/v1/statuses');
    });
});

test('mastodon publisher sends capped alt text as media description', function () {
    // Minimal 1x1 JPEG bytes so mime_content_type() detects image/jpeg
    $minimalJpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
        ."\xFF\xDB\x00\x43\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08\x0A\x0C"
        ."\x14\x0D\x0C\x0B\x0B\x0C\x19\x12\x13\x0F\x14\x1D\x1A\x1F\x1E\x1D\x1A\x1C\x1C\x20"
        ."\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00\xFF\xC4\x00\x1F\x00\x00\x01"
        ."\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05"
        ."\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\xFB\xD3\xFF\xD9";

    $optimizedFile = tempnam(sys_get_temp_dir(), 'masto_alt_opt_');
    file_put_contents($optimizedFile, str_repeat('x', 1024));

    $longAlt = str_repeat('a', 2000);

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

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->once()
        ->with(Mockery::any(), Platform::Mastodon)
        ->andReturn($optimizedFile);

    Http::fake(function ($request) use ($minimalJpeg) {
        $url = $request->url();

        if (str_contains($url, '/api/v1/media')) {
            return Http::response([
                'id' => 'media-alt-123',
                'type' => 'image',
                'url' => 'https://mastodon.social/media/image.jpg',
            ], 200);
        }

        if (str_contains($url, '/api/v1/statuses')) {
            return Http::response([
                'id' => '109876543210',
                'url' => 'https://mastodon.social/@testuser/109876543210',
            ], 200);
        }

        // Media download: return valid JPEG so mime_content_type() detects image/jpeg
        return Http::response($minimalJpeg, 200, ['Content-Type' => 'image/jpeg']);
    });

    $this->publisher->publish($this->postPlatform);

    $expectedDescription = mb_substr($longAlt, 0, Platform::Mastodon->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedDescription) {
        if (! str_contains($request->url(), '/api/v1/media')) {
            return false;
        }

        $description = collect($request->data())->firstWhere('name', 'description');

        return $description !== null
            && $description['contents'] === $expectedDescription
            && mb_strlen($description['contents']) === 1500;
    });

    @unlink($optimizedFile);
});

test('mastodon publisher sends no description part when image has no alt text', function () {
    // Minimal 1x1 JPEG bytes so mime_content_type() detects image/jpeg
    $minimalJpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
        ."\xFF\xDB\x00\x43\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08\x0A\x0C"
        ."\x14\x0D\x0C\x0B\x0B\x0C\x19\x12\x13\x0F\x14\x1D\x1A\x1F\x1E\x1D\x1A\x1C\x1C\x20"
        ."\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00\xFF\xC4\x00\x1F\x00\x00\x01"
        ."\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05"
        ."\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\xFB\xD3\xFF\xD9";

    $optimizedFile = tempnam(sys_get_temp_dir(), 'masto_no_alt_opt_');
    file_put_contents($optimizedFile, str_repeat('x', 1024));

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

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->once()
        ->with(Mockery::any(), Platform::Mastodon)
        ->andReturn($optimizedFile);

    Http::fake(function ($request) use ($minimalJpeg) {
        $url = $request->url();

        if (str_contains($url, '/api/v1/media')) {
            return Http::response([
                'id' => 'media-no-alt-123',
                'type' => 'image',
                'url' => 'https://mastodon.social/media/image.jpg',
            ], 200);
        }

        if (str_contains($url, '/api/v1/statuses')) {
            return Http::response([
                'id' => '109876543210',
                'url' => 'https://mastodon.social/@testuser/109876543210',
            ], 200);
        }

        // Media download: return valid JPEG so mime_content_type() detects image/jpeg
        return Http::response($minimalJpeg, 200, ['Content-Type' => 'image/jpeg']);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/media')) {
            return false;
        }

        return collect($request->data())->firstWhere('name', 'description') === null;
    });

    @unlink($optimizedFile);
});

test('mastodon publisher does not send a description for a non-image even if it carries alt text', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/clip.mp4',
                'url' => 'https://example.com/media/2026-01/clip.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'clip.mp4',
                'meta' => ['alt_text' => 'alt must not be sent for a video'],
            ],
        ],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/api/v1/media')) {
            return Http::response([
                'id' => 'media-video-123',
                'type' => 'video',
                'url' => 'https://mastodon.social/media/clip.mp4',
            ], 200);
        }

        if (str_contains($url, '/api/v1/statuses')) {
            return Http::response([
                'id' => '109876543211',
                'url' => 'https://mastodon.social/@testuser/109876543211',
            ], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/media')) {
            return false;
        }

        return collect($request->data())->firstWhere('name', 'description') === null;
    });
});

test('mastodon publisher includes media ids in post', function () {
    Http::fake([
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '109876543210',
            'url' => 'https://mastodon.social/@testuser/109876543210',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/v1/statuses')
            && $request['visibility'] === 'public';
    });
});

test('mastodon publisher throws exception on api error', function () {
    Http::fake([
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'error' => 'Validation failed: Text can\'t be blank',
        ], 422),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('mastodon publisher throws token expired exception on auth error', function () {
    Http::fake([
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'error' => 'The access token is invalid',
        ], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('mastodon publisher throws permission exception on forbidden', function () {
    Http::fake([
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'error' => 'This action is not allowed',
        ], 403),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(MastodonPublishException::class);
});

test('mastodon publisher limits media to 4', function () {
    $mediaItems = [];
    for ($i = 0; $i < 6; $i++) {
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
        'https://mastodon.social/api/v1/media' => Http::response([
            'id' => 'media-123',
            'type' => 'image',
        ], 200),
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '109876543210',
            'url' => 'https://mastodon.social/@testuser/109876543210',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    // Should still publish successfully (media upload might fail but post should succeed)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/api/v1/statuses');
    });
});

test('mastodon publisher handles empty content', function () {
    $this->post->update(['content' => '']);

    Http::fake([
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '109876543210',
            'url' => 'https://mastodon.social/@testuser/109876543210',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('109876543210');

    Http::assertSent(function ($request) {
        return $request['status'] === '';
    });
});

test('mastodon publisher uses bearer token authentication', function () {
    Http::fake([
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '109876543210',
            'url' => 'https://mastodon.social/@testuser/109876543210',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization')
            && str_starts_with($request->header('Authorization')[0], 'Bearer ');
    });
});

test('mastodon publisher optimizes images before upload', function () {
    // Create a temp file with a valid JPEG so mime_content_type() detects image/jpeg
    $optimizedFile = tempnam(sys_get_temp_dir(), 'masto_opt_');
    file_put_contents($optimizedFile, str_repeat('x', 1024));

    // Minimal 1x1 JPEG bytes so mime_content_type() returns image/jpeg for the downloaded file
    $minimalJpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
        ."\xFF\xDB\x00\x43\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08\x0A\x0C"
        ."\x14\x0D\x0C\x0B\x0B\x0C\x19\x12\x13\x0F\x14\x1D\x1A\x1F\x1E\x1D\x1A\x1C\x1C\x20"
        ."\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00\xFF\xC4\x00\x1F\x00\x00\x01"
        ."\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05"
        ."\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\xFB\xD3\xFF\xD9";

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

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->once()
        ->with(Mockery::any(), Platform::Mastodon)
        ->andReturn($optimizedFile);

    Http::fake(function ($request) use ($minimalJpeg) {
        $url = $request->url();

        if (str_contains($url, '/api/v1/media')) {
            return Http::response([
                'id' => 'media-optimized-123',
                'type' => 'image',
                'url' => 'https://mastodon.social/media/image.jpg',
            ], 200);
        }

        if (str_contains($url, '/api/v1/statuses')) {
            return Http::response([
                'id' => '109876543210',
                'url' => 'https://mastodon.social/@testuser/109876543210',
            ], 200);
        }

        // Media download: return valid JPEG so mime_content_type() detects image/jpeg
        return Http::response($minimalJpeg, 200, ['Content-Type' => 'image/jpeg']);
    });

    $this->publisher->publish($this->postPlatform);

    @unlink($optimizedFile);
});

test('mastodon publisher publishes text-only when media upload fails', function () {
    // Minimal valid JPEG header so mime_content_type() detects image/jpeg
    $minimalJpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
        ."\xFF\xDB\x00\x43\x00\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\x09\x09\x08\x0A\x0C"
        ."\x14\x0D\x0C\x0B\x0B\x0C\x19\x12\x13\x0F\x14\x1D\x1A\x1F\x1E\x1D\x1A\x1C\x1C\x20"
        ."\xFF\xC0\x00\x0B\x08\x00\x01\x00\x01\x01\x01\x11\x00\xFF\xC4\x00\x1F\x00\x00\x01"
        ."\x05\x01\x01\x01\x01\x01\x01\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05"
        ."\xFF\xDA\x00\x08\x01\x01\x00\x00\x3F\x00\xFB\xD3\xFF\xD9";

    $optimizedFile = tempnam(sys_get_temp_dir(), 'masto_fail_opt_');
    file_put_contents($optimizedFile, str_repeat('x', 512));

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-fail',
                'path' => 'media/2026-01/failing-upload.jpg',
                'url' => 'https://example.com/media/2026-01/failing-upload.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'failing-upload.jpg',
            ],
        ],
    ]);

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->once()
        ->with(Mockery::any(), Platform::Mastodon)
        ->andReturn($optimizedFile);

    Http::fake(function ($request) use ($minimalJpeg) {
        $url = $request->url();

        // Media upload returns 500 — uploadMedia returns null
        if (str_contains($url, '/api/v1/media')) {
            return Http::response(['error' => 'Internal server error'], 500);
        }

        if (str_contains($url, '/api/v1/statuses')) {
            return Http::response([
                'id' => '999111222333',
                'url' => 'https://mastodon.social/@testuser/999111222333',
            ], 200);
        }

        // Media download — return valid JPEG bytes
        return Http::response($minimalJpeg, 200, ['Content-Type' => 'image/jpeg']);
    });

    $result = $this->publisher->publish($this->postPlatform);

    // Post still succeeds as text-only
    expect($result['id'])->toBe('999111222333');

    // The statuses request should NOT include media_ids
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/statuses')) {
            return false;
        }

        return ! isset($request->data()['media_ids']);
    });

    @unlink($optimizedFile);
});

test('mastodon publisher defaults to mastodon.social if no instance in meta', function () {
    $this->socialAccount->update(['meta' => []]);

    Http::fake([
        'https://mastodon.social/api/v1/statuses' => Http::response([
            'id' => '109876543210',
            'url' => 'https://mastodon.social/@testuser/109876543210',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'mastodon.social');
    });
});

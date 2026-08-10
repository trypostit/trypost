<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\PinterestPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\PinterestPublisher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'pinterest_user_123',
        'username' => 'pinner',
        'token_expires_at' => now()->addDays(30),
        'meta' => [
            'default_board_id' => 'board_123',
        ],
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Check out this pin!',
    ]);

    $this->postPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Pinterest,
        'content_type' => ContentType::PinterestPin,
        'meta' => ['board_id' => 'board_123'],
    ]);

    $this->publisher = new PinterestPublisher;

    // Mock MediaOptimizer to return the same temp file path
    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('optimizeImage')->andReturnUsing(function (string $tempFile) {
        // Copy to a new temp file to simulate optimization
        $optimized = tempnam(sys_get_temp_dir(), 'pin_opt_');
        copy($tempFile, $optimized);

        return $optimized;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);
});

test('pinterest publisher can publish image pin', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'id' => 'pin_123456',
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('pin_123456');
    expect($result['url'])->toBe('https://pinterest.com/pin/pin_123456');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins');
    });
});

test('pinterest publisher throws exception when no media for pin', function () {
    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Pinterest requires at least one image');
});

test('pinterest publisher throws exception when no board id', function () {
    $this->postPlatform->update(['meta' => []]);
    $this->socialAccount->update(['meta' => []]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Pinterest board_id is required');
});

test('pinterest publisher throws exception for carousel when no board id', function () {
    $this->postPlatform->update([
        'content_type' => ContentType::PinterestCarousel,
        'meta' => [],
    ]);
    $this->socialAccount->update(['meta' => []]);

    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/img1.jpg', 'url' => 'https://example.com/img1.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'img1.jpg'],
            ['id' => 'm2', 'path' => 'media/img2.jpg', 'url' => 'https://example.com/img2.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'img2.jpg'],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Pinterest board_id is required');
});

test('pinterest publisher throws exception for video pin when no board id', function () {
    $this->postPlatform->update([
        'content_type' => ContentType::PinterestVideoPin,
        'meta' => [],
    ]);
    $this->socialAccount->update(['meta' => []]);

    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/video.mp4', 'url' => 'https://example.com/video.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'video.mp4'],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Pinterest board_id is required');
});

test('pinterest publisher uses default board id from account', function () {
    $this->postPlatform->update(['meta' => []]); // No board_id in post meta

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'id' => 'pin_123456',
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins')
            && $request['board_id'] === 'board_123'; // from account meta
    });
});

test('pinterest publisher can publish carousel', function () {
    $mediaItems = [];
    for ($i = 1; $i <= 3; $i++) {
        $mediaItems[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/image{$i}.jpg",
            'url' => "https://example.com/media/2026-01/image{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "image{$i}.jpg",
        ];
    }
    $this->postPlatform->update(['content_type' => ContentType::PinterestCarousel]);

    $this->post->update([

        'media' => $mediaItems,

    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'id' => 'carousel_pin_123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('carousel_pin_123');

    Http::assertSent(function ($request) {
        return $request['media_source']['source_type'] === 'multiple_image_urls'
            && count($request['media_source']['items']) === 3;
    });
});

test('pinterest publisher throws exception for carousel with less than 2 images', function () {
    $this->postPlatform->update(['content_type' => ContentType::PinterestCarousel]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Pinterest carousel requires 2-5 images');
});

test('pinterest publisher throws exception for carousel with more than 5 images', function () {
    $mediaItems = [];
    for ($i = 1; $i <= 6; $i++) {
        $mediaItems[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/image{$i}.jpg",
            'url' => "https://example.com/media/2026-01/image{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "image{$i}.jpg",
        ];
    }
    $this->postPlatform->update(['content_type' => ContentType::PinterestCarousel]);

    $this->post->update([

        'media' => $mediaItems,

    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Pinterest carousel requires 2-5 images');
});

test('pinterest publisher throws exception on api error', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'code' => 400,
            'message' => 'Invalid request',
        ], 400),
        '*' => Http::response('fake-image-content', 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('pinterest publisher throws token expired exception on auth error', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'code' => 1,
            'message' => 'Invalid access token',
        ], 401),
        '*' => Http::response('fake-image-content', 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('pinterest publisher refreshes token when expired', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/oauth/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 2592000,
        ], 200),
        '*/v5/pins' => Http::response([
            'id' => 'pin_123456',
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth/token');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('pinterest publisher throws TokenExpiredException when refresh_token is rejected', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/oauth/token' => Http::response([
            'error' => 'invalid_request',
            'error_description' => 'Refresh token expired',
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'Refresh token expired');
});

test('pinterest publisher includes title and link when provided', function () {
    $this->postPlatform->update([
        'meta' => [
            'board_id' => 'board_123',
            'title' => 'My Pin Title',
            'link' => 'https://example.com/my-page',
        ],
    ]);

    $this->post->update([
        'content' => 'Check out this pin!',
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'id' => 'pin_123456',
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins')
            && $request['title'] === 'My Pin Title'
            && $request['link'] === 'https://example.com/my-page'
            && $request['description'] === 'Check out this pin!';
    });
});

test('pinterest publisher uses post content as description and ignores meta description', function () {
    $this->post->update([
        'content' => 'Shared post caption',
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    $this->postPlatform->update([
        'meta' => [
            'board_id' => 'board_123',
            'description' => 'Stale meta description must be ignored',
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response(['id' => 'pin_123456'], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins')
            && $request['description'] === 'Shared post caption';
    });
});

test('pinterest publisher omits description when post content is blank', function () {
    $this->post->update([
        'content' => '',
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response(['id' => 'pin_123456'], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins')
            && ! array_key_exists('description', $request->data());
    });
});

test('pinterest publisher truncates title to 100 characters', function () {
    $longTitle = str_repeat('T', 150);

    $this->postPlatform->update([
        'meta' => [
            'board_id' => 'board_123',
            'title' => $longTitle,
        ],
    ]);

    $this->post->update([
        'content' => 'Within limit',
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response(['id' => 'pin_truncated'], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins')
            && mb_strlen($request['title']) === 100
            && $request['description'] === 'Within limit';
    });
});

test('pinterest publisher omits blank title and link', function () {
    $this->postPlatform->update([
        'meta' => [
            'board_id' => 'board_123',
            'title' => '',
            'link' => '',
        ],
    ]);

    $this->post->update([
        'content' => 'Caption only',
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response(['id' => 'pin_no_title_link'], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_contains($request->url(), '/v5/pins')
            && $data['description'] === 'Caption only'
            && ! array_key_exists('title', $data)
            && ! array_key_exists('link', $data);
    });
});

test('pinterest publisher sends alt text from image meta capped to platform max', function () {
    $longAlt = str_repeat('x', 600);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
                'meta' => ['alt_text' => $longAlt],
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'id' => 'pin_123456',
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $expectedAlt = mb_substr($longAlt, 0, Platform::Pinterest->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        return str_contains($request->url(), '/v5/pins')
            && data_get($request->data(), 'alt_text') === $expectedAlt
            && strlen($expectedAlt) === Platform::Pinterest->altTextMaxLength();
    });
});

test('pinterest publisher omits alt_text when image has no alt text set', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'id' => 'pin_123456',
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins')
            && ! array_key_exists('alt_text', $request->data());
    });
});

test('pinterest publisher ignores postPlatform meta alt_text and only reads image alt', function () {
    $this->postPlatform->update([
        'meta' => [
            'board_id' => 'board_123',
            'alt_text' => 'legacy per-platform alt text that should be ignored',
        ],
    ]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    Http::fake([
        '*/v5/pins' => Http::response([
            'id' => 'pin_123456',
        ], 200),
        '*' => Http::response('fake-image-content', 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins')
            && ! array_key_exists('alt_text', $request->data());
    });
});

test('pinterest publisher can get boards', function () {
    Http::fake([
        '*/v5/boards*' => Http::response([
            'items' => [
                ['id' => 'board_1', 'name' => 'Board 1'],
                ['id' => 'board_2', 'name' => 'Board 2'],
            ],
        ], 200),
    ]);

    $result = $this->publisher->getBoards($this->socialAccount);

    expect($result['boards'])->toHaveCount(2)
        ->and($result['boards'][0]['id'])->toBe('board_1')
        ->and($result['truncated'])->toBeFalse();
});

test('pinterest publisher paginates boards via bookmark', function () {
    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => Http::sequence()
            ->push([
                'items' => [
                    ['id' => 'board_1', 'name' => 'Board 1'],
                ],
                'bookmark' => 'page-2',
            ], 200)
            ->push([
                'items' => [
                    ['id' => 'board_2', 'name' => 'Board 2'],
                ],
                'bookmark' => 'page-3',
            ], 200)
            ->push([
                'items' => [
                    ['id' => 'board_3', 'name' => 'Board 3'],
                ],
            ], 200),
    ]);

    $result = $this->publisher->getBoards($this->socialAccount);

    expect($result['boards'])->toHaveCount(3)
        ->and($result['boards'][0]['id'])->toBe('board_1')
        ->and($result['boards'][1]['id'])->toBe('board_2')
        ->and($result['boards'][2]['id'])->toBe('board_3')
        ->and($result['truncated'])->toBeFalse();

    Http::assertSentCount(3);
});

test('pinterest publisher stops board pagination on a repeated bookmark', function () {
    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => Http::sequence()
            ->push([
                'items' => [['id' => 'board_1', 'name' => 'Board 1']],
                'bookmark' => 'stuck',
            ], 200)
            ->push([
                'items' => [['id' => 'board_2', 'name' => 'Board 2']],
                'bookmark' => 'stuck',
            ], 200)
            ->push([
                'items' => [['id' => 'should_not_fetch', 'name' => 'Nope']],
            ], 200),
    ]);

    $result = $this->publisher->getBoards($this->socialAccount);

    expect($result['boards'])->toHaveCount(2)
        ->and(collect($result['boards'])->pluck('id')->all())->toBe(['board_1', 'board_2'])
        ->and($result['truncated'])->toBeTrue();

    Http::assertSentCount(2);
});

test('pinterest publisher can publish video pin', function () {
    $this->postPlatform->update(['content_type' => ContentType::PinterestVideoPin]);

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

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl) {
        $url = $request->url();

        // Step 1: Register media
        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_789',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [
                    'key' => 'uploads/video.mp4',
                    'AWSAccessKeyId' => 'FAKE_KEY',
                ],
            ], 201);
        }

        // Step 2: S3 upload
        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        // Step 3: Media status check
        if (str_contains($url, '/v5/media/media_video_789')) {
            return Http::response(['status' => 'succeeded'], 200);
        }

        // Step 4: Create pin
        if (str_contains($url, '/v5/pins')) {
            return Http::response(['id' => 'video_pin_999'], 200);
        }

        // Video download
        return Http::response('fake-video-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('video_pin_999');
    expect($result['url'])->toBe('https://pinterest.com/pin/video_pin_999');

    Http::assertSent(fn ($request) => $request->url() === config('trypost.platforms.pinterest.api').'/pins'
        && data_get($request->data(), 'media_source.source_type') === 'video_id'
        && data_get($request->data(), 'media_source.cover_image_key_frame_time') === 0);
});

test('pinterest publisher includes title description and link on video pins', function () {
    $this->postPlatform->update([
        'content_type' => ContentType::PinterestVideoPin,
        'meta' => [
            'board_id' => 'board_123',
            'title' => 'Video Title',
            'link' => 'https://example.com/video',
        ],
    ]);

    $this->post->update([
        'content' => 'Video pin caption',
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

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_789',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => ['key' => 'uploads/video.mp4'],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_789')) {
            return Http::response(['status' => 'succeeded'], 200);
        }

        if (str_contains($url, '/v5/pins')) {
            return Http::response(['id' => 'video_pin_999'], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(fn ($request) => $request->url() === config('trypost.platforms.pinterest.api').'/pins'
        && data_get($request->data(), 'title') === 'Video Title'
        && data_get($request->data(), 'description') === 'Video pin caption'
        && data_get($request->data(), 'link') === 'https://example.com/video'
        && data_get($request->data(), 'media_source.source_type') === 'video_id');
});

test('pinterest publisher includes title description and link on carousels', function () {
    $mediaItems = [];
    for ($i = 1; $i <= 2; $i++) {
        $mediaItems[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/image{$i}.jpg",
            'url' => "https://example.com/media/2026-01/image{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "image{$i}.jpg",
        ];
    }

    $this->postPlatform->update([
        'content_type' => ContentType::PinterestCarousel,
        'meta' => [
            'board_id' => 'board_123',
            'title' => 'Carousel Title',
            'link' => 'https://example.com/carousel',
        ],
    ]);

    $this->post->update([
        'content' => 'Carousel pin caption',
        'media' => $mediaItems,
    ]);

    Http::fake([
        '*/v5/pins' => Http::response(['id' => 'carousel_pin_123'], 200),
    ]);

    $this->publisher->publish($this->postPlatform->fresh());

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v5/pins')
            && $request['title'] === 'Carousel Title'
            && $request['description'] === 'Carousel pin caption'
            && $request['link'] === 'https://example.com/carousel'
            && $request['media_source']['source_type'] === 'multiple_image_urls';
    });
});

test('pinterest video pin uses the provided cover image url over the default frame', function () {
    $this->postPlatform->update([
        'content_type' => ContentType::PinterestVideoPin,
        'meta' => ['board_id' => 'board_123', 'cover_image_url' => 'https://example.com/cover.jpg'],
    ]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_789',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_789')) {
            return Http::response(['status' => 'succeeded'], 200);
        }

        if (str_contains($url, '/v5/pins')) {
            return Http::response(['id' => 'video_pin_cover'], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => $request->url() === config('trypost.platforms.pinterest.api').'/pins'
        && data_get($request->data(), 'media_source.cover_image_url') === 'https://example.com/cover.jpg'
        && data_get($request->data(), 'media_source.cover_image_key_frame_time') === null);
});

test('pinterest publisher throws exception for unsupported content type', function () {
    $this->postPlatform->update(['content_type' => ContentType::InstagramFeed]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Unsupported content type');
});

test('pinterest publisher waits through pending video processing before creating the pin', function () {
    Sleep::fake();

    $this->postPlatform->update(['content_type' => ContentType::PinterestVideoPin]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';
    $statusChecks = 0;

    Http::fake(function ($request) use ($s3UploadUrl, &$statusChecks) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_pending',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_pending')) {
            $statusChecks++;

            return Http::response([
                'status' => $statusChecks < 3 ? 'processing' : 'succeeded',
            ], 200);
        }

        if (str_contains($url, '/v5/pins')) {
            return Http::response(['id' => 'video_pin_after_wait'], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('video_pin_after_wait')
        ->and($statusChecks)->toBe(3);

    Sleep::assertSleptTimes(2);
});

test('pinterest publisher treats video processing timeout as platform unavailable for retry', function () {
    Sleep::fake();

    $this->postPlatform->update(['content_type' => ContentType::PinterestVideoPin]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_timeout',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_timeout')) {
            return Http::response(['status' => 'processing'], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(PlatformUnavailableException::class, 'Pinterest media processing timeout after 60 attempts');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/pins'));
    Sleep::assertSleptTimes(59);
});

test('pinterest publisher fails hard when video processing reports failed', function () {
    Sleep::fake();

    $this->postPlatform->update(['content_type' => ContentType::PinterestVideoPin]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_failed',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_failed')) {
            return Http::response([
                'status' => 'failed',
                'failure_code' => 'VIDEO_TOO_LONG',
            ], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(PinterestPublishException::class, 'Pinterest media processing failed: VIDEO_TOO_LONG');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/pins'));
    Sleep::assertNeverSlept();
});

test('pinterest publisher treats media status 401 as token expired', function () {
    Sleep::fake();

    $this->postPlatform->update(['content_type' => ContentType::PinterestVideoPin]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_401',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_401')) {
            return Http::response(['message' => 'Access token has expired or been revoked'], 401);
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'Access token has expired or been revoked');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/pins'));
    Sleep::assertNeverSlept();
});

test('pinterest publisher keeps polling through transient media status http errors', function () {
    Sleep::fake();

    $this->postPlatform->update(['content_type' => ContentType::PinterestVideoPin]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';
    $statusChecks = 0;

    Http::fake(function ($request) use ($s3UploadUrl, &$statusChecks) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_5xx',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_5xx')) {
            $statusChecks++;

            if ($statusChecks < 3) {
                return Http::response(['message' => 'temporary error'], 503);
            }

            return Http::response(['status' => 'succeeded'], 200);
        }

        if (str_contains($url, '/v5/pins')) {
            return Http::response(['id' => 'video_pin_after_5xx'], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('video_pin_after_5xx')
        ->and($statusChecks)->toBe(3);

    Sleep::assertSleptTimes(2);
});

test('pinterest publisher treats unknown media status as still processing until timeout', function (string $status) {
    Sleep::fake();

    $this->postPlatform->update(['content_type' => ContentType::PinterestVideoPin]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl, $status) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_unknown',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_unknown')) {
            return Http::response(['status' => $status], 200);
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(PlatformUnavailableException::class, 'Pinterest media processing timeout after 60 attempts');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/pins'));
    Sleep::assertSleptTimes(59);
})->with([
    'registered',
    'not_a_real_status',
    '',
]);

test('pinterest publisher treats media status connection errors as platform unavailable', function () {
    Sleep::fake();

    $this->postPlatform->update(['content_type' => ContentType::PinterestVideoPin]);

    $this->post->update([
        'media' => [[
            'id' => 'test-media-video',
            'path' => 'media/2026-01/video.mp4',
            'url' => 'https://example.com/media/2026-01/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);

    $s3UploadUrl = 'https://pinterest-media-upload.s3.amazonaws.com/upload';

    Http::fake(function ($request) use ($s3UploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/v5/media') && $request->method() === 'POST') {
            return Http::response([
                'media_id' => 'media_video_conn',
                'upload_url' => $s3UploadUrl,
                'upload_parameters' => [],
            ], 201);
        }

        if ($url === $s3UploadUrl) {
            return Http::response('', 204);
        }

        if (str_contains($url, '/v5/media/media_video_conn')) {
            throw new ConnectionException('cURL error 28: Connection timed out');
        }

        return Http::response('fake-video-content', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(PlatformUnavailableException::class, 'Pinterest media status check connection failed');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/pins'));
    Sleep::assertNeverSlept();
});

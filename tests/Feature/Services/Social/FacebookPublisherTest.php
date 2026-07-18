<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\FacebookPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

function facebookJpegBytes(int $width = 1200, int $height = 800): string
{
    $manager = new ImageManager(Driver::class);
    $image = $manager->createImage($width, $height)->fill('888888');

    return (string) $image->encodeUsingMediaType('image/jpeg', quality: 80);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'page_123',
        'username' => 'myfbpage',
        'token_expires_at' => null, // Facebook page tokens don't expire
        'meta' => [
            'page_id' => 'page_123',
            'user_id' => 'fb_user_123',
        ],
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Check out this Facebook post!',
    ]);

    $this->postPlatform = PostPlatform::factory()->facebook()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookPost,
    ]);

    $this->publisher = new FacebookPublisher;
});

test('facebook publisher can publish text only post', function () {
    Http::fake([
        '*/page_123/feed' => Http::response([
            'id' => 'page_123_post_456',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('page_123_post_456');
    expect($result['url'])->toBe('https://www.facebook.com/page_123_post_456');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/feed')
            && $request['message'] === 'Check out this Facebook post!';
    });
});

test('facebook publisher can publish single image post', function () {
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
        '*/page_123/photos' => Http::response([
            'id' => 'photo_123',
            'post_id' => 'page_123_photo_post_456',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result['id'])->toBe('page_123_photo_post_456');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/photos')
            && $request['message'] === 'Check out this Facebook post!';
    });
});

test('facebook publisher can publish multi image post', function () {
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
    $this->post->update([
        'media' => $mediaItems]);

    Http::fake([
        '*/page_123/photos' => Http::sequence()
            ->push(['id' => 'photo_1'], 200)
            ->push(['id' => 'photo_2'], 200)
            ->push(['id' => 'photo_3'], 200),
        '*/page_123/feed' => Http::response([
            'id' => 'page_123_multi_post_789',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result['id'])->toBe('page_123_multi_post_789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/feed')
            && str_contains($request->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
            && ($request->data()['attached_media[0]'] ?? null) === json_encode(['media_fbid' => 'photo_1'])
            && ($request->data()['attached_media[1]'] ?? null) === json_encode(['media_fbid' => 'photo_2']);
    });
});

test('facebook publisher sends graph api requests as form-urlencoded not json', function () {
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
        '*/page_123/photos' => Http::response(['id' => 'photo_123', 'post_id' => 'post_123'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/photos')
            && str_contains($request->header('Content-Type')[0] ?? '', 'application/x-www-form-urlencoded')
            && ! str_contains($request->header('Content-Type')[0] ?? '', 'application/json');
    });
});

test('facebook publisher can publish video post', function () {
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
        '*/page_123/videos' => Http::response([
            'id' => 'video_123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result['id'])->toBe('video_123');
    expect($result['url'])->toBe('https://www.facebook.com/page_123/videos/video_123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/videos')
            && $request['description'] === 'Check out this Facebook post!';
    });
});

test('facebook publisher can publish reel', function () {
    $this->postPlatform->update(['content_type' => ContentType::FacebookReel]);

    $this->post->update([

        'media' => [
            [
                'id' => 'test-media-reel',
                'path' => 'media/2026-01/reel.mp4',
                'url' => 'https://example.com/media/2026-01/reel.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'reel.mp4',
            ],
        ],

    ]);

    Http::fake([
        '*/page_123/video_reels' => Http::sequence()
            ->push([
                'video_id' => 'reel_video_123',
                'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/reel_video_123',
            ], 200)
            ->push(['id' => 'reel_123', 'success' => true], 200),
        '*example.com/media/*' => Http::response('fake-video-binary-content', 200),
        '*rupload.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result['id'])->toBe('reel_123');
    expect($result['url'])->toBe('https://www.facebook.com/reel/reel_123');

    // Assert the transfer phase: POST raw bytes to upload_url (rupload
    // host) with OAuth header and the required Offset + file_size
    // headers Facebook's rupload validator demands.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'rupload.facebook.com')) {
            return false;
        }

        return ($request->header('Offset')[0] ?? null) === '0'
            && ($request->header('file_size')[0] ?? null) === (string) strlen('fake-video-binary-content')
            && str_starts_with($request->header('Authorization')[0] ?? '', 'OAuth ');
    });
});

test('facebook publisher fails reel publish when start does not return upload_url', function () {
    $this->postPlatform->update(['content_type' => ContentType::FacebookReel]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-reel',
                'path' => 'media/2026-01/reel.mp4',
                'url' => 'https://example.com/media/2026-01/reel.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'reel.mp4',
            ],
        ],
    ]);

    // Missing upload_url in the start response — should not silently
    // proceed to a broken transfer (which is what the old code did).
    Http::fake([
        '*/page_123/video_reels' => Http::response([
            'video_id' => 'reel_video_123',
        ], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(
            FacebookPublishException::class,
            'Facebook did not return upload_url for reel start.'
        );
});

test('facebook publisher fails reel publish with typed exception when media download fails', function () {
    $this->postPlatform->update(['content_type' => ContentType::FacebookReel]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-reel',
                'path' => 'media/2026-01/reel.mp4',
                'url' => 'https://example.com/media/2026-01/reel.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'reel.mp4',
            ],
        ],
    ]);

    // start succeeds, but the media URL returns 404 — should surface as
    // a typed FacebookPublishException (ServerError) instead of leaking
    // a generic Exception that would land in the 'unknown' bucket.
    Http::fake([
        '*/page_123/video_reels' => Http::response([
            'video_id' => 'reel_video_123',
            'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/reel_video_123',
        ], 200),
        '*example.com/media/*' => Http::response('', 404),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(
            FacebookPublishException::class,
            'Could not download media for Facebook reel.'
        );
});

test('facebook publisher rejects image story', function () {
    $this->postPlatform->update(['content_type' => ContentType::FacebookStory]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-story',
                'path' => 'media/2026-01/story.jpg',
                'url' => 'https://example.com/media/2026-01/story.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'story.jpg',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(FacebookPublishException::class, 'Facebook Stories require a video file.');
});

test('facebook publisher can publish video story', function () {
    $this->postPlatform->update(['content_type' => ContentType::FacebookStory]);

    $this->post->update([

        'media' => [
            [
                'id' => 'test-media-video-story',
                'path' => 'media/2026-01/story.mp4',
                'url' => 'https://example.com/media/2026-01/story.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'story.mp4',
            ],
        ],

    ]);

    Http::fake([
        '*/page_123/video_stories' => Http::sequence()
            ->push(['video_id' => 'story_video_123'], 200)
            ->push(['post_id' => 'video_story_post_123'], 200),
        '*/story_video_123' => Http::response(['success' => true], 200),
        '*' => Http::response('', 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result['id'])->toBe('video_story_post_123');
});

test('facebook publisher throws exception on api error', function () {
    Http::fake([
        '*/page_123/feed' => Http::response([
            'error' => [
                'message' => 'Invalid request',
                'type' => 'GraphMethodException',
                'code' => 100,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('facebook publisher throws token expired exception on oauth error', function () {
    Http::fake([
        '*/page_123/feed' => Http::response([
            'error' => [
                'message' => 'Error validating access token',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('facebook publisher throws token expired exception on session expired subcode', function () {
    Http::fake([
        '*/page_123/feed' => Http::response([
            'error' => [
                'message' => 'Session has expired',
                'type' => 'OAuthException',
                'code' => 190,
                'error_subcode' => 463,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('facebook publisher throws exception for unsupported content type', function () {
    $this->postPlatform->update(['content_type' => ContentType::InstagramFeed]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Unsupported Facebook content type');
});

test('facebook publisher throws exception when multi image upload fails', function () {
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
    $this->post->update([
        'media' => $mediaItems]);

    Http::fake([
        '*/page_123/photos' => Http::response([
            'error' => ['message' => 'Upload failed'],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Failed to upload any images to Facebook');
});

test('facebook publisher throws exception for unsupported media type', function () {
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
        ->toThrow(Exception::class, 'Unsupported media type for Facebook');
});

test('facebook publisher throws exception for text post with null content', function () {
    $this->post->update(['content' => null]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Facebook text posts require content');
});

test('facebook publisher cleans up temp files after reel upload', function () {
    $this->postPlatform->update(['content_type' => ContentType::FacebookReel]);

    $this->post->update([

        'media' => [
            [
                'id' => 'test-media-reel',
                'path' => 'media/2026-01/reel.mp4',
                'url' => 'https://example.com/media/2026-01/reel.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'reel.mp4',
            ],
        ],

    ]);

    Http::fake([
        '*/page_123/video_reels' => Http::sequence()
            ->push([
                'video_id' => 'reel_video_cleanup_123',
                'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/reel_video_cleanup_123',
            ], 200)
            ->push(['id' => 'reel_cleanup_456', 'success' => true], 200),
        '*example.com/media/*' => Http::response('fake-video', 200),
        '*rupload.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    // Assert no leftover fb_reel_ temp files exist
    $tempDir = sys_get_temp_dir();
    $leftoverFiles = glob("{$tempDir}/fb_reel_*") ?: [];

    expect($leftoverFiles)->toBeEmpty();
});

test('facebook publisher can publish single image with null content', function () {
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
        'https://graph.facebook.com/v25.0/page_123/photos' => Http::response([
            'id' => 'photo-123',
            'post_id' => 'post-123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('post-123');
});

test('single image post without caption omits message from payload', function () {
    $this->post->update([
        'content' => null,
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
        '*/page_123/photos' => Http::response(['id' => 'photo-123', 'post_id' => 'post-123'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/photos')
            && ! array_key_exists('message', $request->data());
    });
});

test('multi-image post without caption omits message from payload', function () {
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

    $this->post->update(['content' => null, 'media' => $mediaItems]);

    Http::fake([
        '*/page_123/photos' => Http::sequence()
            ->push(['id' => 'photo_1'], 200)
            ->push(['id' => 'photo_2'], 200),
        '*/page_123/feed' => Http::response(['id' => 'multi_post_789'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/feed')
            && ! array_key_exists('message', $request->data());
    });
});

test('video post without description omits description from payload', function () {
    $this->post->update([
        'content' => null,
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
        '*/page_123/videos' => Http::response(['id' => 'video_123'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/videos')
            && ! array_key_exists('description', $request->data());
    });
});

test('reel post without description omits description from payload (finish phase)', function () {
    $this->postPlatform->update(['content_type' => ContentType::FacebookReel]);

    $this->post->update([
        'content' => null,
        'media' => [
            [
                'id' => 'test-media-reel',
                'path' => 'media/2026-01/reel.mp4',
                'url' => 'https://example.com/media/2026-01/reel.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'reel.mp4',
            ],
        ],
    ]);

    Http::fake([
        '*/page_123/video_reels' => Http::sequence()
            ->push([
                'video_id' => 'reel_video_123',
                'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/reel_video_123',
            ], 200)
            ->push(['id' => 'reel_123', 'success' => true], 200),
        '*example.com/media/*' => Http::response('fake-video-binary-content', 200),
        '*rupload.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/page_123/video_reels')) {
            return false;
        }

        $data = $request->data();

        return data_get($data, 'upload_phase') === 'finish'
            && ! array_key_exists('description', $data);
    });
});

test('facebook single image post applies the selected aspect ratio crop and uploads the crop', function (string $ratio, float $expected) {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => $ratio]]);
    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
        ],
    ]);

    Http::fake([
        'https://example.com/media/a.jpg' => Http::response(facebookJpegBytes(1600, 900), 200),
        '*/page_123/photos' => Http::response(['id' => 'photo_1', 'post_id' => 'post_1'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $crops = collect(Storage::allFiles())->filter(fn (string $path) => str_starts_with($path, 'social-crops/'));
    expect($crops)->toHaveCount(1);

    $manager = new ImageManager(Driver::class);
    $tempFile = tempnam(sys_get_temp_dir(), 'verify_');
    file_put_contents($tempFile, Storage::get($crops->first()));
    $image = $manager->decodePath($tempFile);
    expect(round($image->width() / $image->height(), 2))->toBe(round($expected, 2));
    @unlink($tempFile);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/page_123/photos')
        && str_contains($request['url'], 'social-crops/')
        && ! str_contains($request['url'], 'example.com'));
})->with([
    '1:1' => ['1:1', 1.0],
    '4:5' => ['4:5', 4 / 5],
    '16:9' => ['16:9', 16 / 9],
]);

test('facebook multi image post applies the chosen aspect ratio crop to every image', function (string $aspectRatio, float $expected) {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => $aspectRatio]]);
    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
            ['id' => 'm2', 'path' => 'media/b.jpg', 'url' => 'https://example.com/media/b.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'b.jpg'],
        ],
    ]);

    Http::fake([
        'https://example.com/media/a.jpg' => Http::response(facebookJpegBytes(1600, 900), 200),
        'https://example.com/media/b.jpg' => Http::response(facebookJpegBytes(900, 1600), 200),
        '*/page_123/photos' => Http::sequence()
            ->push(['id' => 'up_1'], 200)
            ->push(['id' => 'up_2'], 200),
        '*/page_123/feed' => Http::response(['id' => 'post_1'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $crops = collect(Storage::allFiles())->filter(fn (string $path) => str_starts_with($path, 'social-crops/'));
    expect($crops)->toHaveCount(2);

    $manager = new ImageManager(Driver::class);
    foreach ($crops as $cropPath) {
        $tempFile = tempnam(sys_get_temp_dir(), 'verify_');
        file_put_contents($tempFile, Storage::get($cropPath));
        $image = $manager->decodePath($tempFile);
        expect(abs($image->width() / $image->height() - $expected))->toBeLessThan(0.01);
        @unlink($tempFile);
    }

    Http::assertSent(fn ($request) => str_contains($request->url(), '/page_123/photos')
        && str_contains($request['url'] ?? '', 'social-crops/'));
})->with([
    '1:1' => ['1:1', 1.0],
    '4:5' => ['4:5', 4 / 5],
]);

test('facebook multi image post aborts entirely when one image cannot be downloaded for cropping', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => '4:5']]);
    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
            ['id' => 'm2', 'path' => 'media/b.jpg', 'url' => 'https://example.com/media/b.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'b.jpg'],
        ],
    ]);

    Http::fake([
        'https://example.com/media/a.jpg' => Http::response('', 404),
        'https://example.com/media/b.jpg' => Http::response(facebookJpegBytes(900, 1600), 200),
        '*/page_123/photos' => Http::response(['id' => 'up'], 200),
        '*/page_123/feed' => Http::response(['id' => 'post_1'], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(FacebookPublishException::class, 'Failed to download image for cropping');
});

test('facebook image post throws when the source image cannot be downloaded for cropping', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => '4:5']]);
    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
        ],
    ]);

    Http::fake([
        'https://example.com/media/a.jpg' => Http::response('', 404),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(FacebookPublishException::class, 'Failed to download image for cropping');
});

test('facebook image post throws a clean exception when the crop source is not decodable', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => '4:5']]);
    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
        ],
    ]);

    Http::fake([
        'https://example.com/media/a.jpg' => Http::response('<html>error</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(FacebookPublishException::class, 'Failed to process image for cropping');
});

test('facebook single image post without aspect ratio uploads the original image (no crop)', function () {
    Storage::fake();

    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
        ],
    ]);

    Http::fake([
        '*/page_123/photos' => Http::response(['id' => 'photo_1', 'post_id' => 'post_1'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    expect(collect(Storage::allFiles())->filter(fn (string $path) => str_starts_with($path, 'social-crops/')))->toBeEmpty();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/page_123/photos')
        && $request['url'] === 'https://example.com/media/a.jpg');
});

test('facebook single image post with original aspect ratio uploads the original image (no crop)', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => 'original']]);
    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
        ],
    ]);

    Http::fake([
        '*/page_123/photos' => Http::response(['id' => 'photo_1', 'post_id' => 'post_1'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    expect(collect(Storage::allFiles())->filter(fn (string $path) => str_starts_with($path, 'social-crops/')))->toBeEmpty();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/page_123/photos')
        && $request['url'] === 'https://example.com/media/a.jpg');
});

test('facebook publisher sends capped alt text on single image post', function () {
    $longAlt = str_repeat('a', 1500);

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
        '*/page_123/photos' => Http::response(['id' => 'photo_123', 'post_id' => 'post_123'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $expectedAlt = mb_substr($longAlt, 0, Platform::Facebook->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        return str_contains($request->url(), '/page_123/photos')
            && data_get($request->data(), 'alt_text_custom') === $expectedAlt
            && strlen($expectedAlt) === Platform::Facebook->altTextMaxLength();
    });
});

test('facebook publisher sends capped alt text for each image in multi image post', function () {
    $longAlt1 = str_repeat('b', 1200);
    $longAlt2 = str_repeat('c', 1200);

    $this->post->update([
        'media' => [
            [
                'id' => 'm1',
                'path' => 'media/2026-01/image1.jpg',
                'url' => 'https://example.com/media/2026-01/image1.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image1.jpg',
                'meta' => ['alt_text' => $longAlt1],
            ],
            [
                'id' => 'm2',
                'path' => 'media/2026-01/image2.jpg',
                'url' => 'https://example.com/media/2026-01/image2.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image2.jpg',
                'meta' => ['alt_text' => $longAlt2],
            ],
        ],
    ]);

    Http::fake([
        '*/page_123/photos' => Http::sequence()
            ->push(['id' => 'photo_1'], 200)
            ->push(['id' => 'photo_2'], 200),
        '*/page_123/feed' => Http::response(['id' => 'multi_post_alt'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $expectedAlt1 = mb_substr($longAlt1, 0, Platform::Facebook->altTextMaxLength());
    $expectedAlt2 = mb_substr($longAlt2, 0, Platform::Facebook->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt1) {
        return str_contains($request->url(), '/page_123/photos')
            && data_get($request->data(), 'alt_text_custom') === $expectedAlt1;
    });

    Http::assertSent(function ($request) use ($expectedAlt2) {
        return str_contains($request->url(), '/page_123/photos')
            && data_get($request->data(), 'alt_text_custom') === $expectedAlt2;
    });
});

test('facebook publisher omits alt_text_custom from photos payload when no alt text is set', function () {
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
        '*/page_123/photos' => Http::response(['id' => 'photo_123', 'post_id' => 'post_123'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/page_123/photos')
            && ! array_key_exists('alt_text_custom', $request->data());
    });
});

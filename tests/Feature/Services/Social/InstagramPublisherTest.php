<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\InstagramPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

function fakeJpegBytes(int $width = 1200, int $height = 800): string
{
    $manager = new ImageManager(Driver::class);
    $image = $manager->createImage($width, $height)->fill('888888');

    return (string) $image->encodeUsingMediaType('image/jpeg', quality: 80);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_123456789',
        'username' => 'testuser',
        'token_expires_at' => now()->addDays(60),
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from Instagram!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
    ]);

    $this->publisher = new InstagramPublisher;
});

test('instagram publisher throws exception without media', function () {
    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Instagram requires at least one image or video');
});

test('instagram publisher can publish single image', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'media-123456789',
        ], 200),
        'https://graph.instagram.com/v25.0/media-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/ABC123/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('media-123456789');
    expect($result['url'])->toBe('https://www.instagram.com/p/ABC123/');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/ig_123456789/media');
    });
});

test('instagram publisher can publish reel', function () {
    $this->postPlatform->update(['content_type' => ContentType::InstagramReel]);

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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'reel-123456789',
        ], 200),
        'https://graph.instagram.com/v25.0/reel-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/reel/ABC123/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('reel-123456789');
    expect($result['url'])->toBe('https://www.instagram.com/reel/ABC123/');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/ig_123456789/media')
            && (str_contains($request->body(), 'REELS') || str_contains($request->url(), 'media_publish'));
    });
});

test('instagram publisher fits an image story to 9:16 and posts the hosted copy', function () {
    Storage::fake();
    $this->postPlatform->update(['content_type' => ContentType::InstagramStory]);

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

    // The fit itself is covered by MediaOptimizerTest; here we only assert the
    // story is built from the hosted, fitted copy (9:16 canvas).
    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('fitToCanvas')->once()->with(Mockery::type('string'), 1080, 1920)->andReturnUsing(function (string $tempFile) {
        $out = tempnam(sys_get_temp_dir(), 'ig_fit_');
        copy($tempFile, $out);

        return $out;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'story-container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/story-container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'story-123456789',
        ], 200),
        'https://graph.instagram.com/v25.0/story-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/stories/testuser/123/',
        ], 200),
        '*' => Http::response(file_get_contents(__DIR__.'/../../../fixtures/1x1.png'), 200, ['Content-Type' => 'image/png']),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('story-123456789');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/ig_123456789/media') || str_contains($request->url(), 'media_publish')) {
            return false;
        }
        $imageUrl = (string) data_get($request->data(), 'image_url', '');

        return str_contains($imageUrl, 'social-crops/') && ! str_contains($imageUrl, 'example.com');
    });
});

test('instagram publisher fits a real off-ratio story image to a 1080x1920 jpeg', function () {
    Storage::fake();
    $this->postPlatform->update(['content_type' => ContentType::InstagramStory]);

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

    Http::fake([
        'https://example.com/media/2026-01/story.jpg' => Http::response(fakeJpegBytes(1200, 800), 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'story-container-123'], 200),
        'https://graph.instagram.com/v25.0/story-container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'story-123456789'], 200),
        'https://graph.instagram.com/v25.0/story-123456789*' => Http::response(['permalink' => 'https://www.instagram.com/stories/testuser/123/'], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('story-123456789');

    $hosted = collect(Storage::allFiles())->first(fn (string $path) => str_starts_with($path, 'social-crops/'));
    expect($hosted)->not->toBeNull();

    $tempFile = tempnam(sys_get_temp_dir(), 'verify_story_');
    file_put_contents($tempFile, Storage::get($hosted));
    $fitted = (new ImageManager(Driver::class))->decodePath($tempFile);
    expect($fitted->width())->toBe(1080)
        ->and($fitted->height())->toBe(1920);
    @unlink($tempFile);
});

test('instagram publisher throws a clean exception when the story image is not decodable', function () {
    Storage::fake();
    $this->postPlatform->update(['content_type' => ContentType::InstagramStory]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-story',
                'path' => 'media/story.jpg',
                'url' => 'https://example.com/media/story.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'story.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://example.com/media/story.jpg' => Http::response('<html>error</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(InstagramPublishException::class, 'Failed to process image for story fitting');
});

test('instagram publisher surfaces a publish exception when the story container fails to create', function () {
    Storage::fake();
    $this->postPlatform->update(['content_type' => ContentType::InstagramStory]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-story',
                'path' => 'media/story.jpg',
                'url' => 'https://example.com/media/story.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'story.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://example.com/media/story.jpg' => Http::response(fakeJpegBytes(1200, 800), 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['error' => ['message' => 'Invalid media', 'code' => 100]], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(InstagramPublishException::class);
});

test('instagram publisher does not leak the fitted temp file when hosting the story image fails', function () {
    $this->postPlatform->update(['content_type' => ContentType::InstagramStory]);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-story',
                'path' => 'media/story.jpg',
                'url' => 'https://example.com/media/story.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'story.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://example.com/media/story.jpg' => Http::response(fakeJpegBytes(1200, 800), 200),
    ]);

    $fittedPath = null;
    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('fitToCanvas')->once()->andReturnUsing(function (string $tempFile) use (&$fittedPath) {
        $fittedPath = tempnam(sys_get_temp_dir(), 'media_fit_');
        copy($tempFile, $fittedPath);

        return $fittedPath;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Storage::shouldReceive('put')->once()->andThrow(new RuntimeException('disk full'));

    expect(fn () => $this->publisher->publish($this->postPlatform))->toThrow(RuntimeException::class);

    expect($fittedPath)->not->toBeNull()
        ->and(file_exists($fittedPath))->toBeFalse();
});

test('instagram publisher can publish video story', function () {
    $this->postPlatform->update(['content_type' => ContentType::InstagramStory]);

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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'story-container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/story-container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'story-video-123456789',
        ], 200),
        'https://graph.instagram.com/v25.0/story-video-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/stories/testuser/456/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('story-video-123456789');
});

test('instagram publisher can publish carousel', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['id' => 'child-3'], 200)
            ->push(['id' => 'carousel-container-123'], 200),
        'https://graph.instagram.com/v25.0/carousel-container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'carousel-123456789',
        ], 200),
        'https://graph.instagram.com/v25.0/carousel-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/CAROUSEL123/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('carousel-123456789');
    expect($result['url'])->toBe('https://www.instagram.com/p/CAROUSEL123/');
});

test('instagram publisher can publish carousel with videos', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-image',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['id' => 'carousel-container-123'], 200),
        'https://graph.instagram.com/v25.0/child-2*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/carousel-container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'carousel-mix-123456789',
        ], 200),
        'https://graph.instagram.com/v25.0/carousel-mix-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/CAROUSELMIX/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('carousel-mix-123456789');
});

test('instagram publisher throws exception on api error', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'error' => [
                'message' => 'Invalid parameter',
                'type' => 'GraphMethodException',
                'code' => 100,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('instagram publisher throws token expired exception on oauth error', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
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

test('instagram publisher throws token expired exception on session expired subcode', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
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

test('instagram publisher throws exception for unsupported content type', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);

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

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Unsupported Instagram content type');
});

test('instagram publisher throws exception when no container id returned', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'success' => true,
            // No id returned
        ], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'No container ID returned');
});

test('instagram publisher handles media processing error', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'status_code' => 'ERROR',
        ], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Instagram media processing failed');
});

test('instagram publisher waits for media processing', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::sequence()
            ->push(['status_code' => 'IN_PROGRESS'], 200)
            ->push(['status_code' => 'IN_PROGRESS'], 200)
            ->push(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'media-123456789',
        ], 200),
        'https://graph.instagram.com/v25.0/media-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/ABC123/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('media-123456789');
});

test('instagram publisher throws exception when all carousel items fail', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'error' => ['message' => 'Upload failed'],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Failed to create any carousel items');
});

test('instagram publisher can publish single image with null content', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'media-null-content',
        ], 200),
        'https://graph.instagram.com/v25.0/media-null-content*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/NULL123/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('media-null-content');
    expect($result['url'])->toBe('https://www.instagram.com/p/NULL123/');
});

test('instagram publisher can publish reel with null content', function () {
    $this->postPlatform->update(['content_type' => ContentType::InstagramReel]);
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'reel-null-content',
        ], 200),
        'https://graph.instagram.com/v25.0/reel-null-content*' => Http::response([
            'permalink' => 'https://www.instagram.com/reel/NULL123/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('reel-null-content');
});

test('instagram publisher can publish carousel with null content', function () {
    $this->post->update([
        'content' => null,
        'media' => [
            [
                'id' => 'test-media-0',
                'path' => 'media/2026-01/test-image-0.jpg',
                'url' => 'https://example.com/media/2026-01/test-image-0.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test-0.jpg',
            ],
            [
                'id' => 'test-media-1',
                'path' => 'media/2026-01/test-image-1.jpg',
                'url' => 'https://example.com/media/2026-01/test-image-1.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test-1.jpg',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['id' => 'carousel-container-123'], 200),
        'https://graph.instagram.com/v25.0/carousel-container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'carousel-null-content',
        ], 200),
        'https://graph.instagram.com/v25.0/carousel-null-content*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/CAROUSELNULL/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('carousel-null-content');
});

test('instagram publisher can publish single image with empty string content', function () {
    $this->post->update([
        'content' => '',
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'media-empty-content',
        ], 200),
        'https://graph.instagram.com/v25.0/media-empty-content*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/EMPTY123/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('media-empty-content');
});

test('instagram publisher routes feed video to reel', function () {
    // InstagramFeed content type with a single video should route to publishReel (REELS media_type)
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-feed-video',
                'path' => 'media/2026-01/feed-video.mp4',
                'url' => 'https://example.com/media/2026-01/feed-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'feed-video.mp4',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'reel-container-999',
        ], 200),
        'https://graph.instagram.com/v25.0/reel-container-999*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'feed-reel-123',
        ], 200),
        'https://graph.instagram.com/v25.0/feed-reel-123*' => Http::response([
            'permalink' => 'https://www.instagram.com/reel/FEEDVID/',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('feed-reel-123');

    // Assert media_type=REELS was sent in the container creation request
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/ig_123456789/media')
            && str_contains($request->body(), 'REELS');
    });
});

test('instagram publisher handles publish failure', function () {
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'error' => [
                'message' => 'Publish failed',
                'type' => 'GraphMethodException',
                'code' => 100,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('feed image is cropped to chosen aspect ratio before publishing', function (string $aspectRatio, float $expected) {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => $aspectRatio]]);

    $this->post->update([
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
        'https://example.com/media/test.jpg' => Http::response(fakeJpegBytes(1200, 800), 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123'], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-1'], 200),
        'https://graph.instagram.com/v25.0/media-1*' => Http::response(['permalink' => 'https://www.instagram.com/p/X/'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $cropped = collect(Storage::allFiles())->first(fn (string $path) => str_starts_with($path, 'social-crops/'));
    expect($cropped)->not->toBeNull();

    $manager = new ImageManager(Driver::class);
    $tempFile = tempnam(sys_get_temp_dir(), 'verify_');
    file_put_contents($tempFile, Storage::get($cropped));
    $image = $manager->decodePath($tempFile);
    expect(abs($image->width() / $image->height() - $expected))->toBeLessThan(0.01);
    @unlink($tempFile);

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/ig_123456789/media')) {
            return false;
        }
        $imageUrl = $request['image_url'] ?? '';

        return str_contains($imageUrl, 'social-crops/')
            && ! str_contains($imageUrl, 'example.com/media/test.jpg');
    });
})->with([
    '1:1' => ['1:1', 1.0],
    '4:5' => ['4:5', 4 / 5],
    '16:9' => ['16:9', 16 / 9],
]);

test('feed image throws when the source image cannot be downloaded for cropping', function () {
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
        ->toThrow(InstagramPublishException::class, 'Failed to download image for cropping');
});

test('story image throws when the source image cannot be downloaded for fitting', function () {
    Storage::fake();

    $this->postPlatform->update(['content_type' => ContentType::InstagramStory]);
    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
        ],
    ]);

    Http::fake([
        'https://example.com/media/a.jpg' => Http::response('', 404),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(InstagramPublishException::class, 'Failed to download image for story fitting');
});

test('feed image throws a clean exception when the crop source is not decodable', function () {
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
        ->toThrow(InstagramPublishException::class, 'Failed to process image for cropping');
});

test('instagram publisher does not leak the cropped temp file when hosting the feed image fails', function () {
    $this->postPlatform->update(['meta' => ['aspect_ratio' => '4:5']]);

    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
        ],
    ]);

    Http::fake([
        'https://example.com/media/a.jpg' => Http::response(fakeJpegBytes(1200, 800), 200),
    ]);

    $croppedPath = null;
    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('cropToAspectRatio')->once()->andReturnUsing(function (string $tempFile) use (&$croppedPath) {
        $croppedPath = tempnam(sys_get_temp_dir(), 'media_crop_');
        copy($tempFile, $croppedPath);

        return $croppedPath;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Storage::shouldReceive('put')->once()->andThrow(new RuntimeException('disk full'));

    expect(fn () => $this->publisher->publish($this->postPlatform))->toThrow(RuntimeException::class);

    expect($croppedPath)->not->toBeNull()
        ->and(file_exists($croppedPath))->toBeFalse();
});

test('feed image with original aspect ratio bypasses crop', function () {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => 'original']]);

    $this->post->update([
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123'], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-1'], 200),
        'https://graph.instagram.com/v25.0/media-1*' => Http::response(['permalink' => 'https://www.instagram.com/p/X/'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    expect(Storage::allFiles())->toBeEmpty();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/ig_123456789/media')) {
            return false;
        }

        return ($request['image_url'] ?? '') === 'https://example.com/media/test.jpg';
    });
});

test('feed image without aspect_ratio meta uses original URL', function () {
    Storage::fake();

    $this->post->update([
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
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123'], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-1'], 200),
        'https://graph.instagram.com/v25.0/media-1*' => Http::response(['permalink' => 'https://www.instagram.com/p/X/'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    expect(Storage::allFiles())->toBeEmpty();

    Http::assertSent(function ($request) {
        if (! str_ends_with($request->url(), '/ig_123456789/media')) {
            return false;
        }

        return ($request['image_url'] ?? '') === 'https://example.com/media/test.jpg';
    });
});

test('carousel applies the chosen aspect ratio crop to every image', function (string $aspectRatio, float $expected) {
    Storage::fake();

    $this->postPlatform->update(['meta' => ['aspect_ratio' => $aspectRatio]]);

    $this->post->update([
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://example.com/media/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
            ['id' => 'm2', 'path' => 'media/b.jpg', 'url' => 'https://example.com/media/b.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'b.jpg'],
        ],
    ]);

    Http::fake([
        'https://example.com/media/a.jpg' => Http::response(fakeJpegBytes(1600, 900), 200),
        'https://example.com/media/b.jpg' => Http::response(fakeJpegBytes(900, 1600), 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['id' => 'carousel-1'], 200),
        'https://graph.instagram.com/v25.0/child-1*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/child-2*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/carousel-1*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-1'], 200),
        'https://graph.instagram.com/v25.0/media-1*' => Http::response(['permalink' => 'https://www.instagram.com/p/X/'], 200),
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
})->with([
    '1:1' => ['1:1', 1.0],
    '4:5' => ['4:5', 4 / 5],
]);

test('instagram publisher sends capped alt text on single image container', function () {
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
        '*/ig_123456789/media' => Http::response(['id' => 'container-123'], 200),
        '*/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        '*/ig_123456789/media_publish' => Http::response(['id' => 'media-alt-123'], 200),
        '*/media-alt-123*' => Http::response(['permalink' => 'https://www.instagram.com/p/ALT123/'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $expectedAlt = mb_substr($longAlt, 0, Platform::Instagram->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        return str_ends_with($request->url(), '/ig_123456789/media')
            && data_get($request->data(), 'alt_text') === $expectedAlt
            && strlen($expectedAlt) === Platform::Instagram->altTextMaxLength();
    });
});

test('instagram publisher omits alt_text from single image container when no alt text is set', function () {
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
        '*/ig_123456789/media' => Http::response(['id' => 'container-123'], 200),
        '*/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        '*/ig_123456789/media_publish' => Http::response(['id' => 'media-no-alt-123'], 200),
        '*/media-no-alt-123*' => Http::response(['permalink' => 'https://www.instagram.com/p/NOALT123/'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/ig_123456789/media')
            && ! array_key_exists('alt_text', $request->data());
    });
});

test('instagram publisher sends alt text on image carousel children but never on video children', function () {
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
        '*/ig_123456789/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['id' => 'carousel-container-123'], 200),
        '*/child-2*' => Http::response(['status_code' => 'FINISHED'], 200),
        '*/carousel-container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        '*/ig_123456789/media_publish' => Http::response(['id' => 'carousel-alt-123'], 200),
        '*/carousel-alt-123*' => Http::response(['permalink' => 'https://www.instagram.com/p/CAROUSELALT/'], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    $expectedAlt = mb_substr($imageAlt, 0, Platform::Instagram->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        $data = $request->data();

        return str_ends_with($request->url(), '/ig_123456789/media')
            && data_get($data, 'image_url') !== null
            && data_get($data, 'alt_text') === $expectedAlt;
    });

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_ends_with($request->url(), '/ig_123456789/media')
            && data_get($data, 'video_url') !== null
            && ! array_key_exists('alt_text', $data);
    });
});

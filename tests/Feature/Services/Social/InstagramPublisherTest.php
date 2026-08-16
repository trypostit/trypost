<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\InstagramPublisher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
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

test('instagram publisher resumes a processing carousel child without recreating child containers', function () {
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
        'https://graph.instagram.com/v25.0/child-2*' => Http::sequence()
            ->push(['status_code' => 'IN_PROGRESS'], 200)
            ->push(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/carousel-container-123*' => Http::response([
            'status_code' => 'FINISHED',
        ], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'id' => 'carousel-resumed-123456789',
        ], 200),
        'https://graph.instagram.com/v25.0/carousel-resumed-123456789*' => Http::response([
            'permalink' => 'https://www.instagram.com/p/CAROUSELRESUMED/',
        ], 200),
    ]);

    try {
        $this->publisher->publish($this->postPlatform);
        test()->fail('Expected the processing carousel child to be rescheduled.');
    } catch (PlatformUnavailableException $exception) {
        expect($exception->context)->toBe([
            'instagram_workflow' => [
                'stage' => 'carousel_children',
                'child_container_ids' => ['child-1', 'child-2'],
                'processing_child_container_ids' => ['child-2'],
            ],
        ])->and($exception->retryDelaySeconds)->toBe(10)
            ->and($exception->maxRetries)->toBe(90);

        $this->postPlatform->update(['error_context' => $exception->context]);
    }

    $result = $this->publisher->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('carousel-resumed-123456789')
        ->and(collect(Http::recorded())->filter(
            fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/ig_123456789/media')
        ))->toHaveCount(3);
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

test('instagram publisher resumes media processing without creating another container', function () {
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

    try {
        $this->publisher->publish($this->postPlatform);
        test()->fail('Expected the in-progress container to be rescheduled.');
    } catch (PlatformUnavailableException $exception) {
        expect($exception->context)->toBe([
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
        ])->and($exception->retryDelaySeconds)->toBe(10)
            ->and($exception->maxRetries)->toBe(90);

        $this->postPlatform->update(['error_context' => $exception->context]);
    }

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(PlatformUnavailableException::class);

    $result = $this->publisher->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('media-123456789');

    Http::assertSentCount(6);
    expect(collect(Http::recorded())->filter(
        fn (array $pair) => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/ig_123456789/media')
    ))->toHaveCount(1);
});

test('instagram publisher does not publish a container that never finishes processing', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'IN_PROGRESS']),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->context)->toBe([
                'instagram_workflow' => [
                    'stage' => 'final_container',
                    'container_id' => 'container-123',
                ],
            ]);
        });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher retries a transient Graph rate-limit on container status', function (int $code) {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'error' => [
                'message' => 'Instagram Platform rate limit reached.',
                'code' => $code,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->httpStatus)->toBe(400)
                ->and($exception->context)->toBe([
                    'instagram_workflow' => [
                        'stage' => 'final_container',
                        'container_id' => 'container-123',
                    ],
                ]);
        });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
})->with([
    'app rate limit' => [4],
    'user rate limit' => [17],
    'instagram buc' => [80002],
]);

test('instagram publisher retries a transient Graph failure on media_publish', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response([
            'error' => [
                'message' => 'An unexpected error has occurred. Please retry your request later.',
                'type' => 'OAuthException',
                'is_transient' => true,
                'code' => 2,
            ],
        ], 500),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->httpStatus)->toBe(500)
                ->and($exception->context)->toBe([
                    'instagram_workflow' => [
                        'stage' => 'final_container',
                        'container_id' => 'container-123',
                    ],
                ]);
        });
});

test('instagram publisher retries a dropped connection on media_publish', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->context)->toBe([
                'instagram_workflow' => [
                    'stage' => 'final_container',
                    'container_id' => 'container-123',
                ],
            ]);
        });
});

test('instagram publisher retries a dropped connection on container status', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->context)->toBe([
                'instagram_workflow' => [
                    'stage' => 'final_container',
                    'container_id' => 'container-123',
                ],
            ]);
        });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher retries a dropped connection on container create', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->getMessage())->toContain('Instagram API unreachable')
                ->and($exception->context)->toBe([]);
        });
});

test('instagram publisher keeps a published media id when the permalink request drops', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'FINISHED']),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-123456789']),
        'https://graph.instagram.com/v25.0/media-123456789*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    expect($this->publisher->publish($this->postPlatform))->toBe([
        'id' => 'media-123456789',
        'url' => null,
    ]);

    expect($this->postPlatform->fresh()->error_context['instagram_workflow'] ?? null)->toBe([
        'stage' => 'final_container',
        'container_id' => 'container-123',
        'media_id' => 'media-123456789',
    ]);
});

test('instagram publisher does not publish again when a transient media_publish already landed', function () {
    $this->postPlatform->update([
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'PUBLISHED']),
    ]);

    expect($this->publisher->publish($this->postPlatform->fresh()))->toBe([
        'id' => 'container-123',
        'url' => null,
    ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher fails a confirmed container status rejection', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response([
            'error' => [
                'message' => 'The requested resource does not exist',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(InstagramPublishException::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher rejects an invalid workflow instead of starting over', function (array $workflow) {
    $this->postPlatform->update([
        'error_context' => ['instagram_workflow' => $workflow],
    ]);

    Http::fake();

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(InstagramPublishException::class, 'Instagram publish state is invalid and cannot be resumed.');

    Http::assertNothingSent();
})->with([
    'unknown stage' => [['stage' => 'unknown', 'container_id' => 'container-123']],
    'final container without id' => [['stage' => 'final_container']],
    'carousel without children' => [['stage' => 'carousel_children', 'child_container_ids' => []]],
]);

test('instagram publisher fails a resumed container that reports ERROR', function () {
    $this->postPlatform->update([
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'ERROR'], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(InstagramPublishException::class, 'Instagram media processing failed');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/media'));
});

test('instagram publisher fails an expired container instead of retrying it', function () {
    $this->postPlatform->update([
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'EXPIRED'], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(function (InstagramPublishException $exception): void {
            expect($exception->userMessage)->toBe('Media container expired. Please try again in a few minutes.')
                ->and($exception->category)->toBe(ErrorCategory::ServerError);
        });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/media'));
});

test('instagram publisher completes a published container without calling media_publish', function () {
    $this->postPlatform->update([
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
        ],
    ]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/container-123')) {
            return Http::response(['status_code' => 'PUBLISHED'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/ig_123456789/media')) {
            return Http::response([
                'data' => [[
                    'id' => 'other-account-post',
                    'permalink' => 'https://www.instagram.com/p/WRONG/',
                ]],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    expect($this->publisher->publish($this->postPlatform->fresh()))->toBe([
        'id' => 'container-123',
        'url' => null,
    ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
    Http::assertNotSent(fn ($request) => $request->method() === 'POST');
});

test('instagram publisher does not bind another account post when a published story has no media id', function () {
    $this->postPlatform->update([
        'content_type' => ContentType::InstagramStory,
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
        ],
    ]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/container-123')) {
            return Http::response(['status_code' => 'PUBLISHED'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/ig_123456789/stories')) {
            return Http::response([
                'data' => [[
                    'id' => 'other-story',
                    'permalink' => 'https://www.instagram.com/stories/testuser/1/',
                ]],
            ], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/ig_123456789/media')) {
            return Http::response([
                'data' => [[
                    'id' => 'feed-must-not-be-used',
                    'permalink' => 'https://www.instagram.com/p/FEED/',
                ]],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    expect($this->publisher->publish($this->postPlatform->fresh()))->toBe([
        'id' => 'container-123',
        'url' => null,
    ]);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/ig_123456789/stories'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher does not bind another reel from /media when published without a media id', function () {
    $this->postPlatform->update([
        'content_type' => ContentType::InstagramReel,
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
        ],
    ]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/container-123')) {
            return Http::response(['status_code' => 'PUBLISHED'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/ig_123456789/media')) {
            return Http::response([
                'data' => [[
                    'id' => 'other-reel',
                    'permalink' => 'https://www.instagram.com/reel/WRONG/',
                ]],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    expect($this->publisher->publish($this->postPlatform->fresh()))->toBe([
        'id' => 'container-123',
        'url' => null,
    ]);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/ig_123456789/stories'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher keeps a published carousel parent id without listing /media', function () {
    $this->postPlatform->update([
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'carousel_children',
                'child_container_ids' => ['child-1', 'child-2'],
                'processing_child_container_ids' => ['child-2'],
            ],
        ],
    ]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/child-2')) {
            return Http::response(['status_code' => 'FINISHED'], 200);
        }

        if ($request->method() === 'POST' && str_ends_with(explode('?', $request->url())[0], '/ig_123456789/media')) {
            return Http::response(['id' => 'carousel-parent-123'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/carousel-parent-123')) {
            return Http::response(['status_code' => 'PUBLISHED'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/ig_123456789/media')) {
            return Http::response([
                'data' => [[
                    'id' => 'other-carousel',
                    'permalink' => 'https://www.instagram.com/p/WRONG/',
                ]],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    expect($this->publisher->publish($this->postPlatform->fresh()))->toBe([
        'id' => 'carousel-parent-123',
        'url' => null,
    ]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn (Request $request) => $request->method() === 'GET' && str_contains($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher resumes a checkpointed media id without listing recent media', function () {
    $this->postPlatform->update([
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
                'media_id' => 'media-persisted',
            ],
        ],
    ]);

    Http::fake(function (Request $request) {
        if ($request->method() === 'GET' && str_contains($request->url(), '/media-persisted')) {
            return Http::response([
                'permalink' => 'https://www.instagram.com/p/PERSISTED/',
            ], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/ig_123456789/media')) {
            return Http::response([
                'data' => [[
                    'id' => 'other-account-post',
                    'permalink' => 'https://www.instagram.com/p/WRONG/',
                ]],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    expect($this->publisher->publish($this->postPlatform->fresh()))->toBe([
        'id' => 'media-persisted',
        'url' => 'https://www.instagram.com/p/PERSISTED/',
    ]);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/container-123'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/ig_123456789/media'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher checkpoints the media id before fetching the permalink', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123'], 200),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => Http::response(['id' => 'media-123456789'], 200),
        'https://graph.instagram.com/v25.0/media-123456789*' => Http::response(['error' => ['message' => 'temporarily unavailable']], 500),
    ]);

    expect($this->publisher->publish($this->postPlatform))->toBe([
        'id' => 'media-123456789',
        'url' => null,
    ]);

    expect($this->postPlatform->fresh()->error_context['instagram_workflow'] ?? null)->toBe([
        'stage' => 'final_container',
        'container_id' => 'container-123',
        'media_id' => 'media-123456789',
    ]);
});

test('instagram facebook publisher recovers a published container on graph.facebook.com', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'ig_fb_123',
        'access_token' => 'page_token_123',
        'token_expires_at' => null,
        'scopes' => Platform::InstagramFacebook->requiredPublishScopes(),
    ]);
    $this->postPlatform->update([
        'social_account_id' => $account->id,
        'platform' => Platform::InstagramFacebook,
        'error_context' => [
            'instagram_workflow' => [
                'stage' => 'final_container',
                'container_id' => 'container-123',
            ],
        ],
    ]);

    $graph = (string) config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake(function (Request $request) use ($graph) {
        expect($request->url())->toStartWith($graph)
            ->and($request->url())->not->toContain('graph.instagram.com');

        if ($request->method() === 'GET' && str_contains($request->url(), '/container-123')) {
            return Http::response(['status_code' => 'PUBLISHED'], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/ig_fb_123/media')) {
            return Http::response([
                'data' => [[
                    'id' => 'other-facebook-post',
                    'permalink' => 'https://www.instagram.com/p/WRONG/',
                ]],
            ], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected']], 500);
    });

    expect($this->publisher->publish($this->postPlatform->fresh()))->toBe([
        'id' => 'container-123',
        'url' => null,
    ]);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'graph.instagram.com'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/ig_fb_123/media'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher retries a 5xx on container status without publishing', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['error' => ['message' => 'Service temporarily unavailable']], 503),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->httpStatus)->toBe(503)
                ->and($exception->context)->toBe([
                    'instagram_workflow' => [
                        'stage' => 'final_container',
                        'container_id' => 'container-123',
                    ],
                ]);
        });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
});

test('instagram publisher retries a 429 on container status without publishing', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    Http::fake([
        'https://graph.instagram.com/v25.0/ig_123456789/media' => Http::response(['id' => 'container-123']),
        'https://graph.instagram.com/v25.0/container-123*' => Http::response(['error' => ['message' => 'Application request limit reached', 'code' => 4]], 429),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(function (PlatformUnavailableException $exception): void {
            expect($exception->httpStatus)->toBe(429)
                ->and($exception->context)->toBe([
                    'instagram_workflow' => [
                        'stage' => 'final_container',
                        'container_id' => 'container-123',
                    ],
                ]);
        });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/media_publish'));
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

<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\InstagramPublisher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => '12345678',
        'access_token' => 'test-token',
    ]);
    $this->post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'content' => 'Test caption']);
    $this->postPlatform = PostPlatform::factory()->instagram()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'content_type' => ContentType::InstagramFeed,
    ]);
});

test('instagram publisher throws exception when no media', function () {
    $publisher = new InstagramPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'Instagram requires at least one image or video.');
});

test('instagram publisher publishes single image', function () {
    Http::fake([
        '*/12345678/media' => Http::response(['id' => 'container-123'], 200),
        '*/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        '*/12345678/media_publish' => Http::response(['id' => 'post-123'], 200),
        '*/post-123*' => Http::response(['permalink' => 'https://instagram.com/p/abc123'], 200),
    ]);

    $this->post->update([
        'media' => [
            ['id' => 'test-img', 'path' => 'medias/test.jpg', 'url' => 'https://example.com/medias/test.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'test.jpg'],
        ]]);

    $publisher = new InstagramPublisher;
    $result = $publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('post-123');
    expect($result['url'])->toBe('https://instagram.com/p/abc123');
});

test('instagram publisher publishes reel', function () {
    $this->postPlatform->update(['content_type' => ContentType::InstagramReel]);

    Http::fake([
        '*/12345678/media' => Http::response(['id' => 'container-123'], 200),
        '*/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        '*/12345678/media_publish' => Http::response(['id' => 'reel-123'], 200),
        '*/reel-123*' => Http::response(['permalink' => 'https://instagram.com/reel/abc123'], 200),
    ]);

    $this->post->update([
        'media' => [
            ['id' => 'test-vid', 'path' => 'medias/test.mp4', 'url' => 'https://example.com/medias/test.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'test.mp4'],
        ]]);

    $publisher = new InstagramPublisher;
    $result = $publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('reel-123');
});

test('instagram publisher publishes story', function () {
    Storage::fake();
    $this->postPlatform->update(['content_type' => ContentType::InstagramStory]);

    $mockOptimizer = Mockery::mock(MediaOptimizer::class);
    $mockOptimizer->shouldReceive('fitToCanvas')->once()->with(Mockery::type('string'), 1080, 1920)->andReturnUsing(function (string $tempFile) {
        $out = tempnam(sys_get_temp_dir(), 'ig_fit_');
        copy($tempFile, $out);

        return $out;
    });
    app()->instance(MediaOptimizer::class, $mockOptimizer);

    Http::fake([
        '*/12345678/media' => Http::response(['id' => 'container-123'], 200),
        '*/container-123*' => Http::response(['status_code' => 'FINISHED'], 200),
        '*/12345678/media_publish' => Http::response(['id' => 'story-123'], 200),
        '*/story-123*' => Http::response(['permalink' => 'https://instagram.com/stories/abc123'], 200),
        '*' => Http::response(file_get_contents(__DIR__.'/../../fixtures/1x1.png'), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->post->update([
        'media' => [
            ['id' => 'test-img', 'path' => 'medias/test.jpg', 'url' => 'https://example.com/medias/test.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'test.jpg'],
        ]]);

    $publisher = new InstagramPublisher;
    $result = $publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('story-123');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/12345678/media') || str_contains($request->url(), 'media_publish')) {
            return false;
        }
        $imageUrl = (string) data_get($request->data(), 'image_url', '');

        return str_contains($imageUrl, 'social-crops/') && ! str_contains($imageUrl, 'example.com');
    });
});

test('instagram publisher throws token expired exception on oauth error', function () {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'type' => 'OAuthException',
                'code' => 190,
                'message' => 'Invalid OAuth access token',
            ],
        ], 400),
    ]);

    $this->post->update([
        'media' => [
            ['id' => 'test-img', 'path' => 'medias/test.jpg', 'url' => 'https://example.com/medias/test.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'test.jpg'],
        ]]);

    $publisher = new InstagramPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('instagram publisher throws exception on api error', function () {
    Http::fake([
        '*' => Http::response([
            'error' => [
                'code' => 100,
                'message' => 'Invalid parameter',
            ],
        ], 400),
    ]);

    $this->post->update([
        'media' => [
            ['id' => 'test-img', 'path' => 'medias/test.jpg', 'url' => 'https://example.com/medias/test.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'test.jpg'],
        ]]);

    $publisher = new InstagramPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\YouTubePublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => 'youtube-channel-123',
        'access_token' => 'test-token',
        'token_expires_at' => now()->addDays(30),
    ]);
    $this->post = Post::factory()->forAccount($this->socialAccount)->create([
        'content' => 'Test YouTube Short description',
        'content_type' => ContentType::YouTubeShort,
    ]);
    $this->post->setRelation('socialAccount', $this->socialAccount);
});

test('youtube publisher throws exception when no media', function () {
    $publisher = new YouTubePublisher;

    expect(fn () => $publisher->publish($this->post))
        ->toThrow(Exception::class, 'YouTube Shorts requires a video to publish.');
});

test('youtube publisher throws exception for non video media', function () {
    $this->post->update([
        'media' => [
            ['id' => 'test-img', 'path' => 'medias/test.jpg', 'url' => 'https://example.com/medias/test.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'test.jpg'],
        ]]);

    $publisher = new YouTubePublisher;

    expect(fn () => $publisher->publish($this->post))
        ->toThrow(Exception::class, 'YouTube Shorts only supports video content.');
});

test('youtube publisher throws exception when no refresh token for expired token', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => null,
    ]);

    $this->post->update([
        'media' => [
            ['id' => 'test-vid', 'path' => 'medias/test.mp4', 'url' => 'https://example.com/medias/test.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'test.mp4'],
        ]]);

    $publisher = new YouTubePublisher;

    expect(fn () => $publisher->publish($this->post))
        ->toThrow(TokenExpiredException::class, 'No refresh token available');
});

test('youtube publisher throws token expired exception on 401', function () {
    $this->post->update([
        'media' => [
            ['id' => 'test-vid', 'path' => 'medias/test.mp4', 'url' => 'https://example.com/medias/test.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'test.mp4'],
        ]]);

    Http::fake([
        '*' => Http::response([
            'error' => 'invalid_token',
            'error_description' => 'Token has expired',
        ], 401),
    ]);

    $publisher = new YouTubePublisher;

    // The error happens before the API call because of file_get_contents
    // So we test the token expired scenario through refreshToken
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'refresh-token',
    ]);

    expect(fn () => $publisher->publish($this->post))
        ->toThrow(TokenExpiredException::class);
});

test('youtube publisher throws token expired exception on invalid grant', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'invalid-refresh-token',
    ]);

    $this->post->update([
        'media' => [
            ['id' => 'test-vid', 'path' => 'medias/test.mp4', 'url' => 'https://example.com/medias/test.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'test.mp4'],
        ]]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been revoked',
        ], 400),
    ]);

    $publisher = new YouTubePublisher;

    expect(fn () => $publisher->publish($this->post))
        ->toThrow(TokenExpiredException::class);
});

test('youtube publisher handles auth error reason', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'refresh-token',
    ]);

    $this->post->update([
        'media' => [
            ['id' => 'test-vid', 'path' => 'medias/test.mp4', 'url' => 'https://example.com/medias/test.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'test.mp4'],
        ]]);

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response([
            'error' => [
                'code' => 401,
                'message' => 'Request had invalid authentication credentials',
                'errors' => [
                    ['reason' => 'authError'],
                ],
            ],
        ], 401),
    ]);

    $publisher = new YouTubePublisher;

    expect(fn () => $publisher->publish($this->post))
        ->toThrow(TokenExpiredException::class);
});

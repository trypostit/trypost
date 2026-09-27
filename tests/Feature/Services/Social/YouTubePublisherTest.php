<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\YouTubePublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\YouTubePublisher;
use Google\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fakeYouTubeUpload(array $responses = []): YouTubePublisher
{
    Http::preventStrayRequests();
    Http::fake(array_replace([
        'https://example.com/video.mp4' => fn () => Http::response(str_repeat('x', 2048)),
        'https://youtube.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, [
            'Location' => 'https://upload.example.test/session',
        ]),
        'https://upload.example.test/session' => Http::response(['id' => 'short-id']),
    ], $responses));

    $client = new Client;
    $client->setHttpClient(Http::buildClient());
    test()->instance(Client::class, $client);

    return new YouTubePublisher;
}

test('youtube description rejects stored invalid data before network work', function (mixed $description, string $key) {
    Http::fake();
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);
    $this->postPlatform->update(['meta' => ['description' => $description]]);
    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))
        ->toThrow(YouTubePublishException::class, __($key));
    Http::assertNothingSent();
})->with([
    'multibyte overflow' => [str_repeat('é', 2501), 'posts.form.youtube.description_max'],
    'ascii overflow' => [str_repeat('a', 5001), 'posts.form.youtube.description_max'],
    'emoji overflow' => [str_repeat('😀', 1251), 'posts.form.youtube.description_max'],
    'invalid metadata type' => [['invalid'], 'posts.form.youtube.description_invalid'],
]);

test('youtube description reaches the resumable upload request', function (?string $description, string $expected) {
    $this->post->update([
        'content' => 'Short title',
        'media' => [[
            'id' => 'video-1',
            'type' => 'video',
            'path' => 'medias/video.mp4',
            'url' => 'https://example.com/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);
    $this->postPlatform->update(['meta' => ['description' => $description]]);
    $tempFile = null;
    $publisher = fakeYouTubeUpload([
        'https://example.com/video.mp4' => function (Request $request, array $options) use (&$tempFile) {
            $tempFile = $options['sink'];

            return Http::response(str_repeat('x', 2048));
        },
    ]);

    $result = $publisher->publish($this->postPlatform->fresh());

    expect($result)->toBe([
        'id' => 'short-id',
        'url' => 'https://www.youtube.com/shorts/short-id',
    ]);
    expect(app(Client::class)->shouldDefer())->toBeFalse();
    $this->assertFileDoesNotExist($tempFile);

    Http::assertSent(function (Request $request) use ($expected): bool {
        if (! str_contains($request->url(), '/upload/youtube/v3/videos')) {
            return false;
        }

        $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

        return $request->method() === 'POST'
            && $payload['snippet']['title'] === 'Short title #Shorts'
            && $payload['snippet']['description'] === $expected
            && $payload['snippet']['categoryId'] === '22'
            && $payload['status']['privacyStatus'] === 'public'
            && $payload['status']['selfDeclaredMadeForKids'] === false;
    });
})->with([
    'custom multiline description' => ["Full text\nhttps://example.com", "Full text\nhttps://example.com"],
    'programming text' => ['if (a < b && c > d) {}', 'if (a < b && c > d) {}'],
    'literal markup' => ['<p>Text about HTML</p>', '<p>Text about HTML</p>'],
    'multibyte byte limit' => [str_repeat('é', 2500), str_repeat('é', 2500)],
    'emoji byte limit' => [str_repeat('😀', 1250), str_repeat('😀', 1250)],
    'surrounding whitespace' => ["  Full text\nhttps://example.com  ", "  Full text\nhttps://example.com  "],
    'null description' => [null, 'Short title'],
    'empty description' => ['', 'Short title'],
    'blank description' => [" \n ", 'Short title'],
    'non-breaking spaces' => ["\u{00A0}\u{00A0}", 'Short title'],
]);

test('youtube description builds independent upload metadata', function () {
    $this->post->update([
        'content' => 'Short title',
        'media' => [[
            'id' => 'video-1',
            'type' => 'video',
            'path' => 'medias/video.mp4',
            'url' => 'https://example.com/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);
    $this->postPlatform->update(['meta' => ['description' => 'First channel description']]);
    $secondAccount = SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDays(7),
    ]);
    $secondPlatform = PostPlatform::factory()->youtube()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $secondAccount->id,
        'meta' => ['description' => 'Second channel description'],
    ]);
    $publisher = fakeYouTubeUpload();

    $publisher->publish($this->postPlatform->fresh());
    $publisher->publish($secondPlatform);

    foreach (['First channel description', 'Second channel description'] as $description) {
        Http::assertSent(function (Request $request) use ($description): bool {
            if (! str_contains($request->url(), '/upload/youtube/v3/videos')) {
                return false;
            }

            $payload = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

            return $payload['snippet']['title'] === 'Short title #Shorts'
                && $payload['snippet']['description'] === $description;
        });
    }
});

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->youtube()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'UC_channel_123',
        'username' => 'mychannel',
        'token_expires_at' => now()->addDays(7),
        'meta' => [
            'channel_id' => 'UC_channel_123',
            'google_user_id' => 'google_user_123',
        ],
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Check out this YouTube Short!',
    ]);

    $this->postPlatform = PostPlatform::factory()->youtube()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::YouTube,
        'content_type' => ContentType::YouTubeShort,
    ]);

    $this->publisher = new YouTubePublisher;
});

test('youtube publisher throws exception when no media', function () {
    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'YouTube Shorts requires a video to publish.');
});

test('youtube publisher cleans up unusable downloads without starting an upload', function (int $status, string $body, string $message) {
    $this->post->update([
        'media' => [[
            'id' => 'video-1',
            'path' => 'medias/video.mp4',
            'url' => 'https://example.com/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);
    $tempFile = null;
    $publisher = fakeYouTubeUpload([
        'https://example.com/video.mp4' => function (Request $request, array $options) use (&$tempFile, $status, $body) {
            $tempFile = $options['sink'];

            return Http::response($body, $status);
        },
    ]);

    expect(fn () => $publisher->publish($this->postPlatform->fresh()))
        ->toThrow(YouTubePublishException::class, $message);

    $this->assertFileDoesNotExist($tempFile);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/upload/youtube/v3/videos'));
})->with([
    'failed download' => [503, '', 'Failed to download video for YouTube upload: HTTP 503'],
    'empty download' => [200, '', 'Downloaded video is too small or empty'],
    'incomplete download' => [200, 'video', 'Downloaded video is too small or empty'],
]);

test('youtube publisher throws exception for non-video content', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-image',
                'path' => 'media/2026-01/image.jpg',
                'url' => 'https://example.com/media/2026-01/image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'image.jpg',
            ],
        ],
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'YouTube Shorts only supports video content.');
});

test('youtube publisher refreshes token when expired', function () {
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
        'https://oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ], 200),
        '*' => Http::response(['error' => ['message' => 'Test']], 400),
    ]);

    try {
        $this->publisher->publish($this->postPlatform);
    } catch (Exception $e) {
        // Expected to fail on upload, but token should be refreshed
    }

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth2.googleapis.com/token');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('youtube publisher throws exception when no refresh token available', function () {
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
        ->toThrow(TokenExpiredException::class, 'No refresh token available for YouTube account');
});

test('youtube publisher reports Google upload errors', function (string $url, int $status, string $reason, string $exception, string $message) {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test-video.mp4',
            ],
        ],
    ]);

    $tempFile = null;
    $publisher = fakeYouTubeUpload([
        'https://example.com/video.mp4' => function (Request $request, array $options) use (&$tempFile) {
            $tempFile = $options['sink'];

            return Http::response(str_repeat('x', 2048));
        },
        $url => Http::response([
            'error' => [
                'code' => $status,
                'message' => 'Invalid request',
                'errors' => [['reason' => $reason, 'message' => 'Invalid request']],
            ],
        ], $status),
    ]);

    expect(fn () => $publisher->publish($this->postPlatform->fresh()))
        ->toThrow($exception, $message);
    expect(app(Client::class)->shouldDefer())->toBeFalse();
    $this->assertFileDoesNotExist($tempFile);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/upload/youtube/v3/videos'));
})->with([
    'invalid description at initialization' => [
        'https://youtube.googleapis.com/upload/youtube/v3/videos*', 400, 'invalidDescription', YouTubePublishException::class, 'Video description is invalid.',
    ],
    'expired token at initialization' => [
        'https://youtube.googleapis.com/upload/youtube/v3/videos*', 401, 'authError', TokenExpiredException::class, 'Invalid request',
    ],
    'invalid description during upload' => [
        'https://upload.example.test/session', 400, 'invalidDescription', YouTubePublishException::class, 'Video description is invalid.',
    ],
]);

test('youtube publisher throws exception with null content', function () {
    $this->postPlatform->update(['meta' => ['description' => 'A valid description is not a title']]);
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

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'YouTube Shorts require a title');
});

test('youtube publisher builds correct title with shorts tag', function () {
    $publisher = new YouTubePublisher;
    $reflection = new ReflectionClass($publisher);
    $method = $reflection->getMethod('buildTitle');
    $method->setAccessible(true);

    // Short content: appends #Shorts
    $title = $method->invoke($publisher, 'My awesome short video');
    expect($title)->toBe('My awesome short video #Shorts');

    // Long content: truncates to leave room for #Shorts tag (100 chars max)
    $longContent = str_repeat('A', 200);
    $title = $method->invoke($publisher, $longContent);
    expect(mb_strlen($title))->toBeLessThanOrEqual(100);
    expect($title)->toEndWith(' #Shorts');

    // Multi-line content: only uses first line before period
    $multiLine = "First sentence. Second part.\nSecond line";
    $title = $method->invoke($publisher, $multiLine);
    expect($title)->toBe('First sentence #Shorts');

    // Newline-separated: stops at newline
    $newlineContent = "Title line\nMore content here";
    $title = $method->invoke($publisher, $newlineContent);
    expect($title)->toBe('Title line #Shorts');
});

test('youtube publisher counts an accented title in characters, not bytes', function () {
    $reflection = new ReflectionClass(YouTubePublisher::class);
    $method = $reflection->getMethod('buildTitle');
    $method->setAccessible(true);

    $publisher = new YouTubePublisher;

    foreach (range(0, 11) as $pad) {
        $title = $method->invoke($publisher, str_repeat('a', $pad).str_repeat('ação ', 30));

        expect(mb_check_encoding($title, 'UTF-8'))->toBeTrue()
            ->and(mb_strlen($title))->toBeLessThanOrEqual(100);
    }

    $accented = str_repeat('ção', 30);

    expect(mb_strlen($accented))->toBe(90)
        ->and(strlen($accented))->toBeGreaterThan(92)
        ->and($method->invoke($publisher, $accented))->toBe($accented.' #Shorts');
});

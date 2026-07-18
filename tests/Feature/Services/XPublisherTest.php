<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\XPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\XPublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'platform_user_id' => 'x-123',
        'username' => 'testuser',
        'access_token' => 'test-token',
        'token_expires_at' => now()->addDays(30),
    ]);
    $this->post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'content' => 'Test tweet']);
    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'content_type' => ContentType::XPost,
    ]);
});

test('x publisher publishes text only tweet', function () {
    Http::fake([
        '*/2/tweets' => Http::response([
            'data' => ['id' => 'tweet-123'],
        ], 201),
    ]);

    $publisher = new XPublisher;
    $result = $publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('tweet-123');
    expect($result['url'])->toBe('https://x.com/testuser/status/tweet-123');
});

test('x publisher throws token expired exception on 401', function () {
    Http::fake([
        '*' => Http::response([
            'title' => 'Unauthorized',
            'detail' => 'Unauthorized',
        ], 401),
    ]);

    $publisher = new XPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('x publisher refreshes token when expired', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'refresh-token-123',
    ]);

    Http::fake([
        '*/2/oauth2/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 7200,
        ], 200),
        '*/2/tweets' => Http::response([
            'data' => ['id' => 'tweet-123'],
        ], 201),
    ]);

    $publisher = new XPublisher;
    $result = $publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('tweet-123');
    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('x publisher throws exception when no refresh token', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => null,
    ]);

    $publisher = new XPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'No refresh token available');
});

test('x publisher throws exception on api error', function () {
    Http::fake([
        '*' => Http::response([
            'title' => 'Bad Request',
            'detail' => 'Invalid tweet content',
        ], 400),
    ]);

    $publisher = new XPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('x publisher returns unknown id when no id in response', function () {
    Http::fake([
        '*/2/tweets' => Http::response([
            'data' => [],
        ], 201),
    ]);

    $publisher = new XPublisher;
    $result = $publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('unknown');
    expect($result['url'])->toBeNull();
});

test('x publisher handles token refresh failure', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'invalid-refresh-token',
    ]);

    Http::fake([
        '*/2/oauth2/token' => Http::response([
            'title' => 'Unauthorized',
            'detail' => 'Invalid refresh token',
        ], 401),
    ]);

    $publisher = new XPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('x publisher does NOT rotate the token when it is only expiring soon but still valid', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->addMinutes(5),
        'refresh_token' => 'refresh-token-123',
    ]);
    $originalAccessToken = $this->socialAccount->access_token;

    Http::fake([
        '*/2/oauth2/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 7200,
        ], 200),
        '*/2/tweets' => Http::response([
            'data' => ['id' => 'tweet-123'],
        ], 201),
    ]);

    $publisher = new XPublisher;
    $result = $publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('tweet-123');

    // A still-valid token is used as-is — the single-use refresh_token is not rotated.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/2/oauth2/token'));
    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe($originalAccessToken);
});

test('x publisher handles 403 error as generic error', function () {
    Http::fake([
        '*' => Http::response([
            'title' => 'Forbidden',
            'detail' => 'You do not have access',
        ], 403),
    ]);

    $publisher = new XPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(XPublishException::class);
});

test('x publisher handles empty error response', function () {
    Http::fake([
        '*' => Http::response(null, 500),
    ]);

    $publisher = new XPublisher;

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\RefreshSocialToken;
use App\Jobs\SendNotification;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
    $this->account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
        'username' => 'testuser',
    ]);
});

test('refresh job routes through verify (access-token-first) not refreshToken', function () {
    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->with(
        Mockery::on(fn ($account) => $account->id === $this->account->id)
    );
    $verifier->shouldNotReceive('refreshToken');
    app()->instance(ConnectionVerifier::class, $verifier);

    (new RefreshSocialToken($this->account))->handle($verifier);
});

test('proactive refresh does NOT rotate the X refresh token while the access token still works', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'should-not-be-used',
            'refresh_token' => 'should-not-be-used',
            'expires_in' => 7200,
        ], 200),
    ]);

    // Token is "expiring soon" (inside the proactive window) but still valid.
    $this->account->update([
        'token_expires_at' => now()->addMinutes(20),
        'refresh_token' => 'original-refresh-token',
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/me'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/oauth2/token'));
    expect($this->account->fresh()->refresh_token)->toBe('original-refresh-token');
    expect($this->account->fresh()->status)->toBe(Status::Connected);
});

test('proactive refresh EXTENDS a still-valid Instagram token (extension-model platform)', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'status' => Status::Connected,
        'access_token' => 'old-ig-token',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    Http::fake([
        config('trypost.platforms.instagram.auth_api').'/refresh_access_token*' => Http::response([
            'access_token' => 'extended-ig-token',
            'expires_in' => 5184000,
        ], 200),
    ]);

    (new RefreshSocialToken($account))->handle(app(ConnectionVerifier::class));

    // Instagram/Threads extend the token itself and can't refresh once expired,
    // so a still-valid token IS extended proactively — unlike rotating platforms.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'refresh_access_token'));
    expect($account->fresh()->access_token)->toBe('extended-ig-token');
});

test('proactive refresh EXTENDS a still-valid Threads token (extension-model platform)', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
        'status' => Status::Connected,
        'access_token' => 'old-threads-token',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    Http::fake([
        config('trypost.platforms.threads.auth_api').'/refresh_access_token*' => Http::response([
            'access_token' => 'extended-threads-token',
            'expires_in' => 5184000,
        ], 200),
    ]);

    (new RefreshSocialToken($account))->handle(app(ConnectionVerifier::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'refresh_access_token'));
    expect($account->fresh()->access_token)->toBe('extended-threads-token');
});

test('proactive refresh does NOT disconnect Instagram on a Meta rate-limit (400 OAuthException code 4)', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'status' => Status::Connected,
        'access_token' => 'valid-ig-token',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    Http::fake([
        config('trypost.platforms.instagram.auth_api').'/refresh_access_token*' => Http::response([
            'error' => ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 4],
        ], 400),
    ]);

    (new RefreshSocialToken($account))->handle(app(ConnectionVerifier::class));

    // A rate-limit is transient — the still-valid token must stay Connected.
    expect($account->fresh()->status)->toBe(Status::Connected);
    expect($account->fresh()->access_token)->toBe('valid-ig-token');
});

test('refresh job marks account as TokenExpired when refresh_token is rejected', function () {
    Queue::fake();

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(
        new TokenExpiredException('refresh_token revoked')
    );
    app()->instance(ConnectionVerifier::class, $verifier);

    (new RefreshSocialToken($this->account))->handle($verifier);

    expect($this->account->fresh()->status)->toBe(Status::TokenExpired);
    expect($this->account->fresh()->error_message)->toBe('refresh_token revoked');

    // Notification dispatched because account transitioned from Connected.
    Queue::assertPushed(SendNotification::class);
});

test('refresh job logs warning on non-token errors and leaves status alone', function () {
    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'Proactive token refresh failed'
            && $context['account_id'] === $this->account->id
            && $context['error'] === 'network blip';
    });

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new RuntimeException('network blip'));
    app()->instance(ConnectionVerifier::class, $verifier);

    (new RefreshSocialToken($this->account))->handle($verifier);

    expect($this->account->fresh()->status)->toBe(Status::Connected);
});

test('refresh job does NOT mark account expired when platform is unavailable', function () {
    Queue::fake();

    Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
        return $message === 'Token refresh skipped: platform unavailable'
            && $context['account_id'] === $this->account->id
            && str_contains($context['error'], '503');
    });

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(
        new PlatformUnavailableException('X API returned 503 during token refresh', 503)
    );
    app()->instance(ConnectionVerifier::class, $verifier);

    (new RefreshSocialToken($this->account))->handle($verifier);

    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

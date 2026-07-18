<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Status;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('verifies account without refresh when token is not expired', function () {
    Http::fake([
        'api.linkedin.com/*' => Http::response(['sub' => '123'], 200),
    ]);

    $account = SocialAccount::factory()->linkedin()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.linkedin.com/rest/userinfo'));
});

test('refreshes linkedin token before verifying when expired', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 5184000,
        ], 200),
        'api.linkedin.com/*' => Http::response(['sub' => '123'], 200),
    ]);

    $account = SocialAccount::factory()->linkedin()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();
    expect($account->fresh()->token_expires_at)->toBeGreaterThan(now());

    Http::assertSent(fn ($request) => str_contains($request->url(), 'linkedin.com/oauth/v2/accessToken'));
});

test('refreshing one linkedin row leaves a sibling linkedin-page row untouched', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 5184000,
        ], 200),
    ]);

    $person = SocialAccount::factory()->linkedin()->create(['refresh_token' => 'old_refresh_token']);
    $page = SocialAccount::factory()->linkedinPage()->create(['refresh_token' => 'old_refresh_token']);

    (new ConnectionVerifier)->refreshToken($person);

    expect($person->fresh()->refresh_token)->toBe('new_refresh_token');
    expect($page->fresh()->refresh_token)->toBe('old_refresh_token');
});

test('refreshes x token before verifying when expired', function () {
    Http::fake([
        'api.x.com/2/oauth2/token' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 7200,
        ], 200),
        'api.x.com/2/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $account = SocialAccount::factory()->x()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();
    expect($account->fresh()->token_expires_at)->toBeGreaterThan(now());

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.x.com/2/oauth2/token'));
});

test('refreshes bluesky token before verifying when expired', function () {
    Http::fake([
        'bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([
            'accessJwt' => 'new_access_token',
            'refreshJwt' => 'new_refresh_token',
        ], 200),
        'bsky.social/xrpc/app.bsky.actor.getProfile*' => Http::response(['did' => 'did:plc:123'], 200),
    ]);

    $account = SocialAccount::factory()->bluesky()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();
    expect($account->fresh()->token_expires_at)->toBeGreaterThan(now());

    Http::assertSent(fn ($request) => str_contains($request->url(), 'refreshSession'));
});

test('refreshes youtube token before verifying when expired', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'new_token',
            'expires_in' => 3600,
        ], 200),
        'www.googleapis.com/youtube/*' => Http::response(['items' => []], 200),
    ]);

    $account = SocialAccount::factory()->youtube()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token'));
});

test('refreshes tiktok token before verifying when expired', function () {
    Http::fake([
        'open.tiktokapis.com/v2/oauth/token/' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 86400,
        ], 200),
        'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => []]], 200),
    ]);

    $account = SocialAccount::factory()->tiktok()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'tiktokapis.com/v2/oauth/token'));
});

test('refreshes pinterest token before verifying when expired', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 86400,
        ], 200),
        'api.pinterest.com/v5/user_account' => Http::response(['username' => 'test'], 200),
    ]);

    $account = SocialAccount::factory()->pinterest()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'pinterest.com/v5/oauth/token'));
});

test('refreshes threads token before verifying when expired', function () {
    Http::fake([
        'graph.threads.net/refresh_access_token*' => Http::response([
            'access_token' => 'new_token',
            'expires_in' => 5184000,
        ], 200),
        'graph.threads.net/v1.0/me*' => Http::response(['id' => '123', 'username' => 'test'], 200),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'refresh_access_token'));
});

test('throws exception when linkedin refresh fails', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $account = SocialAccount::factory()->linkedin()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->verify($account))
        ->toThrow(TokenExpiredException::class, 'Failed to refresh LinkedIn token');
});

test('throws exception when x refresh fails', function () {
    Http::fake([
        'api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $account = SocialAccount::factory()->x()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->verify($account))
        ->toThrow(TokenExpiredException::class, 'Failed to refresh X token');
});

test('does not refresh facebook token as it uses long-lived tokens', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['id' => '123', 'name' => 'Test'], 200),
    ]);

    $account = SocialAccount::factory()->facebook()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();

    // Should only call the verify endpoint, no refresh
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
});

test('refreshes instagram token when expired', function () {
    Http::fake([
        'graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'new-instagram-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ], 200),
        'graph.instagram.com/v25.0/me*' => Http::response(['id' => '123', 'username' => 'test'], 200),
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();

    $account->refresh();
    expect($account->access_token)->toBe('new-instagram-token');
    expect($account->refresh_token)->toBe('new-instagram-token');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'refresh_access_token'));
});

test('threads refresh records a 60-day expiry when the response omits expires_in', function () {
    Http::fake([
        config('trypost.platforms.threads.auth_api').'/refresh_access_token*' => Http::response([
            'access_token' => 'new-threads-token',
        ], 200),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->addHours(2),
    ]);

    (new ConnectionVerifier)->refreshToken($account);

    $account->refresh();
    expect($account->token_expires_at)->not->toBeNull();
    expect($account->token_expires_at->isAfter(now()->addDays(59)))->toBeTrue();
});

test('instagram refresh records a 60-day expiry when the response omits expires_in', function () {
    Http::fake([
        config('trypost.platforms.instagram.auth_api').'/refresh_access_token*' => Http::response([
            'access_token' => 'new-instagram-token',
        ], 200),
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->addHours(2),
    ]);

    (new ConnectionVerifier)->refreshToken($account);

    $account->refresh();
    expect($account->token_expires_at)->not->toBeNull();
    expect($account->token_expires_at->isAfter(now()->addDays(59)))->toBeTrue();
});

test('does NOT refresh proactively when token still works (lazy refresh)', function () {
    Http::fake([
        'api.linkedin.com/*' => Http::response(['sub' => '123'], 200),
    ]);

    // Token is "expiring soon" but access_token still works.
    $account = SocialAccount::factory()->linkedin()->create([
        'token_expires_at' => now()->addMinutes(10),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect($verifier->verify($account))->toBeTrue();

    // Refresh endpoint must NOT have been called — verify worked without it.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'oauth/v2/accessToken'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.linkedin.com/rest/userinfo'));
});

test('refreshes lazily on 401 then retries verify', function () {
    Http::fake([
        // First verify call returns 401, second (after refresh) returns 200.
        'api.linkedin.com/*' => Http::sequence()
            ->push(['error' => 'unauthorized'], 401)
            ->push(['sub' => '123'], 200),
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 5184000,
        ], 200),
    ]);

    $account = SocialAccount::factory()->linkedin()->create([
        'token_expires_at' => now()->addHours(2),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect($verifier->verify($account))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth/v2/accessToken'));
});

test('throws when verify returns 401 AND refresh also fails', function () {
    Http::fake([
        'api.linkedin.com/*' => Http::response(['error' => 'unauthorized'], 401),
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $account = SocialAccount::factory()->linkedin()->create([
        'token_expires_at' => now()->addHours(2),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->verify($account))->toThrow(TokenExpiredException::class);
});

test('forces refresh when token is hard-expired', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 5184000,
        ], 200),
        'api.linkedin.com/*' => Http::response(['sub' => '123'], 200),
    ]);

    $account = SocialAccount::factory()->linkedin()->create([
        'token_expires_at' => now()->subMinutes(5),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect($verifier->verify($account))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth/v2/accessToken'));
});

test('throws when refresh fails AND token is hard-expired', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $account = SocialAccount::factory()->linkedin()->create([
        'token_expires_at' => now()->subMinutes(5),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->verify($account))->toThrow(TokenExpiredException::class);
});

test('5xx during refresh raises PlatformUnavailableException, not TokenExpiredException', function () {
    $service = config('trypost.platforms.bluesky.default_service');

    Http::fake([
        "{$service}/xrpc/com.atproto.server.refreshSession" => Http::response('upstream timeout', 503),
    ]);

    $account = SocialAccount::factory()->bluesky()->create([
        'token_expires_at' => now()->subMinutes(5),
        'refresh_token' => 'old_refresh_token',
        'meta' => ['service' => $service],
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->refreshToken($account))->toThrow(PlatformUnavailableException::class);
});

test('connection failure during refresh raises PlatformUnavailableException', function () {
    Http::fake([
        config('trypost.platforms.youtube.oauth_api').'/token' => fn () => throw new ConnectionException('cURL error 7: connection refused'),
    ]);

    $account = SocialAccount::factory()->youtube()->create([
        'token_expires_at' => now()->subMinutes(5),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->refreshToken($account))->toThrow(PlatformUnavailableException::class);
});

test('does not disconnect when a concurrent refresh already rotated the token (lost-rotation race)', function () {
    Http::fake([
        // Our stale refresh_token is rejected — a concurrent process already used it.
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        // But the access_token the winning refresh persisted still works.
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $account = SocialAccount::factory()->x()->create([
        'status' => Status::Connected,
        'access_token' => 'stale-token',
        'refresh_token' => 'already-rotated',
        'token_expires_at' => now()->subHour(),
    ]);

    // Simulate the concurrent refresh: a separate instance persists a fresh,
    // valid token (through the encrypted cast) while our in-memory copy stays
    // the stale, expired one.
    SocialAccount::find($account->id)->update([
        'access_token' => 'fresh-token',
        'token_expires_at' => now()->addHours(2),
    ]);

    expect((new ConnectionVerifier)->verify($account))->toBeTrue();
    expect($account->fresh()->status)->toBe(Status::Connected);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/me')
        && $request->header('Authorization')[0] === 'Bearer fresh-token');
});

test('does not disconnect when the verify after a refresh 401s once but a fresh token is available', function () {
    Http::fake([
        // Refresh succeeds and rotates the token...
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'refreshed-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 7200,
        ], 200),
        // ...but the verify that follows 401s once (the sub-commit window where a
        // lock-skipped refresh reloads a not-yet-persisted token) before
        // succeeding on the reload + retry.
        config('trypost.platforms.x.api').'/users/me' => Http::sequence()
            ->push(['error' => 'unauthorized'], 401)
            ->push(['data' => ['id' => '123']], 200),
    ]);

    $account = SocialAccount::factory()->x()->create([
        'status' => Status::Connected,
        'access_token' => 'stale-token',
        'refresh_token' => 'old-refresh',
        'token_expires_at' => now()->subHour(),
    ]);

    expect((new ConnectionVerifier)->verify($account))->toBeTrue();
    expect($account->fresh()->status)->toBe(Status::Connected);
});

test('4xx during refresh keeps raising TokenExpiredException', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $account = SocialAccount::factory()->x()->create([
        'token_expires_at' => now()->subMinutes(5),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->refreshToken($account))->toThrow(TokenExpiredException::class);
});

test('429 during refresh raises PlatformUnavailableException (rate limit is transient)', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response(['error' => 'rate_limit_exceeded'], 429),
    ]);

    $account = SocialAccount::factory()->x()->create([
        'token_expires_at' => now()->subMinutes(5),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->refreshToken($account))->toThrow(PlatformUnavailableException::class);
});

test('bluesky 5xx during refresh raises PlatformUnavailable even when password fallback is stored', function () {
    $service = config('trypost.platforms.bluesky.default_service');

    Http::fake([
        "{$service}/xrpc/com.atproto.server.refreshSession" => Http::response('upstream timeout', 503),
        "{$service}/xrpc/com.atproto.server.createSession" => Http::response('upstream timeout', 503),
    ]);

    $account = SocialAccount::factory()->bluesky()->create([
        'token_expires_at' => now()->subMinutes(5),
        'refresh_token' => 'old_refresh_token',
        'meta' => [
            'service' => $service,
            'identifier' => 'user.bsky.social',
            'password' => encrypt('app-password'),
        ],
    ]);

    $verifier = new ConnectionVerifier;

    expect(fn () => $verifier->refreshToken($account))->toThrow(PlatformUnavailableException::class);
});

test('verifies discord by checking the bot is still in the guild', function () {
    config(['trypost.platforms.discord.bot_token' => 'BOTTOKEN']);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*' => Http::response(['id' => '999000111'], 200),
    ]);

    $account = SocialAccount::factory()->discord()->create([
        'platform_user_id' => '999000111',
    ]);

    expect((new ConnectionVerifier)->verify($account))->toBeTrue();
});

test('reports discord disconnected when the bot was removed from the guild', function () {
    config(['trypost.platforms.discord.bot_token' => 'BOTTOKEN']);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*' => Http::response(['message' => 'Unknown Guild'], 404),
    ]);

    $account = SocialAccount::factory()->discord()->create([
        'platform_user_id' => '999000111',
    ]);

    expect((new ConnectionVerifier)->verify($account))->toBeFalse();
});

test('instagram refresh treats a Meta rate-limit (400 OAuthException code 4) as transient, not a dead token', function () {
    Http::fake([
        config('trypost.platforms.instagram.auth_api').'/refresh_access_token*' => Http::response([
            'error' => ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 4],
        ], 400),
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    expect(fn () => (new ConnectionVerifier)->refreshToken($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('threads refresh treats a Meta rate-limit (400 OAuthException code 17) as transient, not a dead token', function () {
    Http::fake([
        config('trypost.platforms.threads.auth_api').'/refresh_access_token*' => Http::response([
            'error' => ['message' => 'User request limit reached', 'type' => 'OAuthException', 'code' => 17],
        ], 400),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    expect(fn () => (new ConnectionVerifier)->refreshToken($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('instagram refresh treats code 190 as a genuinely expired token', function () {
    Http::fake([
        config('trypost.platforms.instagram.auth_api').'/refresh_access_token*' => Http::response([
            'error' => ['message' => 'Access token has expired', 'type' => 'OAuthException', 'code' => 190],
        ], 400),
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    expect(fn () => (new ConnectionVerifier)->refreshToken($account))
        ->toThrow(TokenExpiredException::class);
});

test('instagram verify treats a Meta rate-limit (OAuthException code 4) as still-valid, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/me*' => Http::response([
            'error' => ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 4],
        ], 400),
    ]);

    // Not expired, so verify hits the endpoint directly (no refresh).
    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    // A rate-limit must NOT raise TokenExpiredException (which would disconnect);
    // verify returns false and the caller leaves the account connected.
    expect((new ConnectionVerifier)->verify($account))->toBeFalse();
});

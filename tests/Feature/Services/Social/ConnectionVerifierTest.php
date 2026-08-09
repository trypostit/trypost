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
        config('trypost.platforms.tiktok.api').'/oauth/token/' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 86400,
        ], 200),
        config('trypost.platforms.tiktok.api').'/user/info/*' => Http::response(['data' => ['user' => []]], 200),
    ]);

    $account = SocialAccount::factory()->tiktok()->create([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $verifier = new ConnectionVerifier;
    $result = $verifier->verify($account);

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), config('trypost.platforms.tiktok.api').'/oauth/token'));
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

test('throws TokenExpiredException when the discord bot was removed from the guild', function () {
    config(['trypost.platforms.discord.bot_token' => 'BOTTOKEN']);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*' => Http::response(['message' => 'Unknown Guild'], 404),
    ]);

    $account = SocialAccount::factory()->discord()->create([
        'platform_user_id' => '999000111',
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);

    // Discord has no per-account refresh flow (one bot token shared across
    // every connected account) — a confirmed rejection must not trigger a
    // second, identical getGuild call against that shared budget.
    Http::assertSentCount(1);
});

test('throws TokenExpiredException when the discord bot is no longer in the guild (403)', function () {
    config(['trypost.platforms.discord.bot_token' => 'BOTTOKEN']);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*' => Http::response(['message' => 'Missing Access', 'code' => 50001], 403),
    ]);

    $account = SocialAccount::factory()->discord()->create([
        'platform_user_id' => '999000111',
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);

    Http::assertSentCount(1);
});

test('throws PlatformUnavailableException, not TokenExpiredException, when the shared discord bot token is rejected', function () {
    config(['trypost.platforms.discord.bot_token' => 'BOTTOKEN']);

    // 401 means the app-wide DISCORD_BOT_TOKEN is misconfigured — every
    // Discord-connected account would fail identically. It is not evidence
    // that THIS account's connection is broken, so it must not disconnect it.
    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*' => Http::response(['message' => '401: Unauthorized'], 401),
    ]);

    $account = SocialAccount::factory()->discord()->create([
        'platform_user_id' => '999000111',
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
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

test('instagram verify treats a Meta rate-limit (OAuthException code 4) as transient, not a disconnect', function () {
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
    // it must be surfaced as PlatformUnavailableException so the caller retries
    // next cycle instead of silently — and permanently — treating it as valid.
    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('threads verify treats a dead token reported under a non-190 code as genuinely expired', function () {
    // Meta doesn't always report a dead Threads token as code 190 — this
    // reproduces the "(#100) The requested resource does not exist" case
    // from issue #230, which the old code === 190-only check let through
    // as a silent, un-flagged "still valid".
    Http::fake([
        config('trypost.platforms.threads.graph_api').'/me*' => Http::response([
            'error' => ['message' => 'The requested resource does not exist', 'type' => 'OAuthException', 'code' => 100],
        ], 400),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('threads verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.threads.graph_api').'/me*' => Http::response('upstream timeout', 503),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('facebook verify treats a dead token reported under a non-190 code as genuinely expired', function () {
    Http::fake([
        config('trypost.platforms.facebook.graph_api').'/me*' => Http::response([
            'error' => ['message' => 'The requested resource does not exist', 'type' => 'OAuthException', 'code' => 100],
        ], 400),
    ]);

    $account = SocialAccount::factory()->facebook()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);

    // Facebook Page tokens don't expire — no per-account refresh flow — so a
    // confirmed rejection must not trigger a second, identical /me call.
    Http::assertSentCount(1);
});

test('facebook verify treats a Meta rate-limit as transient, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.facebook.graph_api').'/me*' => Http::response([
            'error' => ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 4],
        ], 400),
    ]);

    $account = SocialAccount::factory()->facebook()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('facebook verify treats a Business Use Case rate-limit (Page token, code 80001) as transient, not a disconnect', function () {
    // Facebook and InstagramFacebook accounts use Page tokens, which are
    // throttled by BUC limits (code 80001) rather than Platform Rate Limits
    // (codes 4/17) — and BUC rejections come back as a plain 400, not 429.
    Http::fake([
        config('trypost.platforms.facebook.graph_api').'/me*' => Http::response([
            'error' => ['message' => 'There have been too many calls to this Page account.', 'code' => 80001],
        ], 400),
    ]);

    $account = SocialAccount::factory()->facebook()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('instagram verify treats a Business Use Case rate-limit (code 80002) as transient, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/me*' => Http::response([
            'error' => ['message' => 'Instagram Platform rate limit reached.', 'code' => 80002],
        ], 400),
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('facebook verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.facebook.graph_api').'/me*' => Http::response('upstream timeout', 503),
    ]);

    $account = SocialAccount::factory()->facebook()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('facebook verify treats a non-JSON failure body as platform unavailable, not a confirmed dead token', function () {
    Http::fake([
        config('trypost.platforms.facebook.graph_api').'/me*' => Http::response('<html>blocked</html>', 400),
    ]);

    $account = SocialAccount::factory()->facebook()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('instagram verify treats a dead token reported under a non-190 code as genuinely expired', function () {
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/me*' => Http::response([
            'error' => ['message' => 'The requested resource does not exist', 'type' => 'OAuthException', 'code' => 100],
        ], 400),
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('instagram verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/me*' => Http::response('upstream timeout', 503),
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('threads refresh treats a dead token reported under a non-190 code as genuinely expired', function () {
    Http::fake([
        config('trypost.platforms.threads.auth_api').'/refresh_access_token*' => Http::response([
            'error' => ['message' => 'The requested resource does not exist', 'type' => 'OAuthException', 'code' => 100],
        ], 400),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    expect(fn () => (new ConnectionVerifier)->refreshToken($account))
        ->toThrow(TokenExpiredException::class);
});

test('threads verify treats a non-JSON failure body as platform unavailable, not a confirmed dead token', function () {
    // A WAF block page, truncated response, or gateway hiccup can return a
    // 4xx with a body that isn't parseable JSON. There's no confirmed
    // rejection from Meta in that case, so it must not disconnect the account.
    Http::fake([
        config('trypost.platforms.threads.graph_api').'/me*' => Http::response('<html>blocked</html>', 400),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('threads verify treats a failed response with a valid but unrecognized JSON shape as a confirmed rejection', function () {
    // Unlike an unparseable body, a body that DID parse but has no "error"
    // key at all is a real response from Meta, just not shaped like its
    // usual error object. This is deliberately NOT treated as transient —
    // an unrecognized 4xx shape still disconnects rather than being
    // silently ignored, which is the exact bug this class replaced.
    Http::fake([
        config('trypost.platforms.threads.graph_api').'/me*' => Http::response(['data' => ['id' => '123']], 400),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->addDays(30),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('threads refresh treats a non-JSON failure body as platform unavailable, not a confirmed dead token', function () {
    Http::fake([
        config('trypost.platforms.threads.auth_api').'/refresh_access_token*' => Http::response('<html>blocked</html>', 400),
    ]);

    $account = SocialAccount::factory()->threads()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    expect(fn () => (new ConnectionVerifier)->refreshToken($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('instagram refresh treats a non-JSON failure body as platform unavailable, not a confirmed dead token', function () {
    Http::fake([
        config('trypost.platforms.instagram.auth_api').'/refresh_access_token*' => Http::response('<html>blocked</html>', 400),
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'token_expires_at' => now()->subHour(),
    ]);

    expect(fn () => (new ConnectionVerifier)->refreshToken($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('throws PlatformUnavailableException when the discord guild lookup fails transiently', function () {
    config(['trypost.platforms.discord.bot_token' => 'BOTTOKEN']);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*' => Http::response(['message' => 'Internal Server Error'], 500),
    ]);

    $account = SocialAccount::factory()->discord()->create([
        'platform_user_id' => '999000111',
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('linkedin verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/userinfo' => Http::response(['message' => 'Internal Server Error'], 500),
    ]);

    $account = SocialAccount::factory()->linkedin()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('linkedin verify throws TokenExpiredException on a bare 401', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400),
        config('trypost.platforms.linkedin.api').'/rest/userinfo' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $account = SocialAccount::factory()->linkedin()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('linkedin page verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/organizationAcls*' => Http::response(['message' => 'Internal Server Error'], 500),
    ]);

    $account = SocialAccount::factory()->linkedinPage()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('linkedin page verify throws TokenExpiredException on a bare 401', function () {
    Http::fake([
        'www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400),
        config('trypost.platforms.linkedin-page.api').'/rest/organizationAcls*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $account = SocialAccount::factory()->linkedinPage()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('x verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/users/me' => Http::response(['title' => 'Internal Server Error'], 503),
    ]);

    $account = SocialAccount::factory()->x()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('x verify throws TokenExpiredException on a bare 401', function () {
    Http::fake([
        'api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        config('trypost.platforms.x.api').'/users/me' => Http::response(['title' => 'Unauthorized'], 401),
    ]);

    $account = SocialAccount::factory()->x()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('x verify throws TokenExpiredException on an unsupported-authentication error type', function () {
    Http::fake([
        'api.x.com/2/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        config('trypost.platforms.x.api').'/users/me' => Http::response([
            'type' => 'https://api.twitter.com/2/problems/unsupported-authentication',
            'title' => 'Unsupported Authentication',
        ], 403),
    ]);

    $account = SocialAccount::factory()->x()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('youtube verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.youtube.data_api').'/channels*' => Http::response(['error' => ['message' => 'Backend Error']], 500),
    ]);

    $account = SocialAccount::factory()->youtube()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('youtube verify throws TokenExpiredException on a bare 401', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        config('trypost.platforms.youtube.data_api').'/channels*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
    ]);

    $account = SocialAccount::factory()->youtube()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('pinterest verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.pinterest.api').'/user_account' => Http::response(['message' => 'Internal Server Error'], 500),
    ]);

    $account = SocialAccount::factory()->pinterest()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('pinterest verify throws TokenExpiredException on a bare 401', function () {
    Http::fake([
        'api.pinterest.com/v5/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        config('trypost.platforms.pinterest.api').'/user_account' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $account = SocialAccount::factory()->pinterest()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('tiktok verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.tiktok.api').'/user/info/*' => Http::response(['error' => ['code' => 'internal_error']], 500),
    ]);

    $account = SocialAccount::factory()->tiktok()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('tiktok verify throws TokenExpiredException on a bare 401 from user/info', function () {
    // /v2/user/info/ only needs the always-granted user.info.basic scope, so
    // unlike a publish-time 401 (which can be a video.publish scope gap), a
    // 401 here is unambiguous — the token itself is dead. A 401 also triggers
    // verify()'s built-in refresh-and-retry, so the refresh endpoint needs a
    // response too, even though the retried verify call fails identically.
    Http::fake([
        config('trypost.platforms.tiktok.api').'/oauth/token/' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 86400,
        ], 200),
        config('trypost.platforms.tiktok.api').'/user/info/*' => Http::response(['error' => ['code' => 'access_token_invalid']], 401),
    ]);

    $account = SocialAccount::factory()->tiktok()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('tiktok verify throws TokenExpiredException on a bare 401 with no recognized error code', function () {
    // Isolates ConnectionVerifier's own status-based check from
    // TikTokPublishException::isConfirmedDeadToken()'s error-code check:
    // 'access_token_revoked' IS present as error.code, but it's not one of
    // the codes that check recognizes (access_token_invalid,
    // access_token_expired, 10001, 10002) — so only the bare
    // `$response->status() === 401` clause in verifyTikTok() can catch it.
    Http::fake([
        config('trypost.platforms.tiktok.api').'/oauth/token/' => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 86400,
        ], 200),
        config('trypost.platforms.tiktok.api').'/user/info/*' => Http::response(['error' => ['code' => 'access_token_revoked']], 401),
    ]);

    $account = SocialAccount::factory()->tiktok()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);

    // 1 refresh call (hits the faked 200) + verify called before, after, and
    // once more when the refresh rotated the token (refreshThenVerify's own
    // retry) — those three all hit the same faked 401, since the fake
    // ignores the token used.
    Http::assertSentCount(4);
});

test('bluesky verify treats a 5xx as platform unavailable, not a disconnect', function () {
    Http::fake([
        config('trypost.platforms.bluesky.default_service').'/xrpc/*' => Http::response(['message' => 'Internal Server Error'], 500),
    ]);

    $account = SocialAccount::factory()->bluesky()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('bluesky verify throws TokenExpiredException when getProfile reports ExpiredToken', function () {
    Http::fake([
        // refreshSession and createSession (password re-auth fallback) both
        // rejected, so refreshThenVerify exhausts every recovery path.
        'bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response(['error' => 'ExpiredToken'], 400),
        'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'InvalidRequest'], 400),
        'bsky.social/xrpc/app.bsky.actor.getProfile*' => Http::response(['error' => 'ExpiredToken'], 400),
    ]);

    $account = SocialAccount::factory()->bluesky()->create();

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);
});

test('mastodon verify treats a 5xx as platform unavailable, not a disconnect', function () {
    $account = SocialAccount::factory()->mastodon()->create();
    $instance = $account->meta['instance'] ?? config('trypost.platforms.mastodon.default_instance');

    Http::fake([
        "{$instance}/api/v1/accounts/verify_credentials" => Http::response(['error' => 'Internal Server Error'], 503),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

test('mastodon verify throws TokenExpiredException on a bare 401, with no refresh attempt (mastodon tokens do not expire)', function () {
    $account = SocialAccount::factory()->mastodon()->create();
    $instance = $account->meta['instance'] ?? config('trypost.platforms.mastodon.default_instance');

    Http::fake([
        "{$instance}/api/v1/accounts/verify_credentials" => Http::response(['error' => 'The access token was revoked'], 401),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);

    // Mastodon has no per-account refresh flow — a confirmed rejection must
    // not trigger a second, identical verify_credentials call.
    Http::assertSentCount(1);
});

test('mastodon verify throws TokenExpiredException on a bare 403 from verify_credentials', function () {
    // verify_credentials is the lowest-privilege read endpoint every
    // authorized app token can reach, so unlike a publish-time 403 on the
    // write-scoped /statuses endpoint (which stays a Permission-category
    // publish failure), a 403 here means the app's authorization itself was
    // revoked. MastodonPublishException::isConfirmedDeadToken() is 401-only
    // by design — this is ConnectionVerifier's own additional check.
    $account = SocialAccount::factory()->mastodon()->create();
    $instance = $account->meta['instance'] ?? config('trypost.platforms.mastodon.default_instance');

    Http::fake([
        "{$instance}/api/v1/accounts/verify_credentials" => Http::response(['error' => 'This action is not allowed'], 403),
    ]);

    expect(fn () => (new ConnectionVerifier)->verify($account))
        ->toThrow(TokenExpiredException::class);

    Http::assertSentCount(1);
});

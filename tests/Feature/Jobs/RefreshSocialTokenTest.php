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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

test('refresh job routes through refreshToken, never the billed verify endpoint', function () {
    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('refreshToken')->once()->with(
        Mockery::on(fn ($account) => $account->id === $this->account->id)
    )->andReturnTrue();
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    (new RefreshSocialToken($this->account))->handle($verifier);
});

test('proactive refresh rotates the X refresh token without disconnecting the account', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 7200,
        ], 200),
    ]);

    // Token is "expiring soon" (inside the proactive window) but still valid.
    $this->account->update([
        'token_expires_at' => now()->addMinutes(20),
        'refresh_token' => 'original-refresh-token',
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // X single-uses the refresh_token, so rotating one proactively has to leave
    // the account healthy instead of tripping a false-positive disconnect.
    expect($this->account->fresh()->refresh_token)->toBe('rotated-refresh-token');
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
    $verifier->shouldReceive('refreshToken')->once()->andThrow(
        new TokenExpiredException('refresh_token revoked')
    );
    // The access_token is dead too, so there is nothing left to fall back to.
    $verifier->shouldReceive('verifyAccessToken')->once()->andThrow(
        new TokenExpiredException('X access token is invalid or expired')
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
    $verifier->shouldReceive('refreshToken')->once()->andThrow(new RuntimeException('network blip'));
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
    $verifier->shouldReceive('refreshToken')->once()->andThrow(
        new PlatformUnavailableException('X API returned 503 during token refresh', 503)
    );
    app()->instance(ConnectionVerifier::class, $verifier);

    (new RefreshSocialToken($this->account))->handle($verifier);

    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

test('proactive refresh renews a still-valid X token without spending a billed user read', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 7200,
        ], 200),
    ]);

    $this->account->update([
        'access_token' => 'original-access-token',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // GET /2/users/me is a billed "User: Read" ($0.010). A successful token
    // refresh already proves the credential works, so it must not be called.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/me'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth2/token'));

    expect($this->account->fresh()->access_token)->toBe('rotated-access-token');
    expect($this->account->fresh()->token_expires_at->isAfter(now()->addHour()))->toBeTrue();
});

test('a successful refresh stamps last_verified_at so other jobs can skip verifying', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 7200,
        ], 200),
    ]);

    $this->account->update([
        'token_expires_at' => now()->addMinutes(20),
        'last_verified_at' => null,
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    expect($this->account->fresh()->last_verified_at)->not->toBeNull();
});

test('a rejected refresh does not disconnect an account whose access token still works', function () {
    Queue::fake();

    Http::fake([
        // X single-uses the refresh_token; a concurrent refresh already burned
        // this one, so the provider rejects it — but the access_token is alive.
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Value passed for the token was invalid.',
        ], 400),
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $this->account->update([
        'access_token' => 'still-valid-access-token',
        'refresh_token' => 'already-consumed-by-a-race',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // PublishToSocialPlatform hard-fails posts for a TokenExpired account, so
    // disconnecting here would kill posts the access_token could still publish.
    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

test('an account with no refresh token stays connected while its access token works', function () {
    Queue::fake();

    Http::fake([
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $this->account->update([
        'access_token' => 'still-valid-access-token',
        'refresh_token' => null,
        'token_expires_at' => now()->addMinutes(20),
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

test('a rejected refresh DOES disconnect once the access token is dead too', function () {
    Queue::fake();

    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'refresh_token revoked',
        ], 400),
        config('trypost.platforms.x.api').'/users/me' => Http::response([
            'title' => 'Unauthorized',
            'status' => 401,
        ], 401),
    ]);

    $this->account->update([
        'access_token' => 'dead-access-token',
        'refresh_token' => 'revoked-refresh-token',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    expect($this->account->fresh()->status)->toBe(Status::TokenExpired);
});

test('lock skipped by a concurrent refresh does not record a verification', function () {
    Http::fake([config('trypost.platforms.x.api').'/*' => Http::response([], 200)]);

    $this->account->update([
        'last_verified_at' => null,
        'token_expires_at' => now()->addMinutes(20),
    ]);

    Cache::lock("token_refresh:{$this->account->id}", 30)->get();

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // Nothing was refreshed here, so nothing was proven — stamping would let
    // the daily sweep skip an account no one actually checked.
    Http::assertNothingSent();
    expect($this->account->fresh()->last_verified_at)->toBeNull();
});

test('a platform with nothing to refresh is never recorded as verified', function () {
    $account = SocialAccount::factory()->mastodon()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
        'last_verified_at' => null,
    ]);

    app(ConnectionVerifier::class)->refreshToken($account);

    expect($account->fresh()->last_verified_at)->toBeNull();
});

test('a refresh whose follow-up verify fails is not recorded as a verification', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'fresh-but-rejected',
            'refresh_token' => 'rt-new',
            'expires_in' => 7200,
        ], 200),
        config('trypost.platforms.x.api').'/users/me' => Http::response(['title' => 'Unauthorized'], 401),
    ]);

    $this->account->update([
        'token_expires_at' => now()->subMinute(),
        'last_verified_at' => null,
    ]);

    try {
        app(ConnectionVerifier::class)->verify($this->account);
    } catch (TokenExpiredException) {
        // expected — the refreshed token is rejected too
    }

    // The refresh succeeded but the credential was never proven good. Stamping
    // here lets both skip-windows wave through an account nobody verified.
    expect($this->account->fresh()->last_verified_at)->toBeNull();
});

test('a refresh that returns an empty access token is not recorded as a verification', function () {
    // TokenRefreshClient classifies on HTTP status alone and never inspects the
    // body, so a 200 carrying an empty token is stored as-is.
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => '',
            'refresh_token' => 'rt-new',
            'expires_in' => 7200,
        ], 200),
    ]);

    $this->account->update([
        'token_expires_at' => now()->addMinutes(20),
        'last_verified_at' => null,
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    expect($this->account->fresh()->last_verified_at)->toBeNull();
});

test('a rejected refresh is not re-sent before the access token is checked', function () {
    Queue::fake();

    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'error' => 'invalid_grant',
        ], 400),
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $this->account->update([
        'access_token' => 'still-valid-access-token',
        'refresh_token' => 'already-consumed-by-a-race',
        'token_expires_at' => now()->subMinute(),
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // Going through verify() would re-send the refresh_token the provider just
    // rejected — and on Bluesky re-run the rate-limited password re-auth.
    $refreshCalls = collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/oauth2/token'))
        ->count();

    expect($refreshCalls)->toBe(1);
});

test('a refresh lost to a concurrent one falls back to the token that won', function () {
    Queue::fake();

    $api = config('trypost.platforms.x.api');
    Http::fake([
        // Our refresh_token was already consumed by the process that won.
        $api.'/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        $api.'/users/me' => function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';

            return str_contains($auth, 'winner-access-token')
                ? Http::response(['data' => ['id' => '123']], 200)
                : Http::response(['title' => 'Unauthorized', 'status' => 401], 401);
        },
    ]);

    $this->account->update([
        'access_token' => 'stale-access-token',
        'refresh_token' => 'stale-refresh-token',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    // The winner persisted its new pair while ours was in flight; this
    // instance still holds the rotated-away one. Written through a separate
    // model so the encrypted casts apply — a raw DB write stores plaintext,
    // and reading it back throws DecryptException instead of exercising this.
    SocialAccount::find($this->account->id)->update([
        'access_token' => 'winner-access-token',
        'refresh_token' => 'winner-refresh-token',
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // The recovery is the point: reload, find the winner's token, verify with
    // it. Asserting the call proves we got that far rather than bailing early.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/me'));
    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

test('the refresh lock outlives the slowest refresh a provider can make us wait', function () {
    // Bluesky refreshes with two sequential calls (refreshSession, then the
    // createSession re-auth). If the lock expires first, a second process
    // refreshes with the same single-use refresh_token and one of the two is
    // rejected. Bounding the calls ourselves keeps that under the lock without
    // holding the lock longer, which the publish path also waits on.
    $worstCaseSeconds = 2 * (ConnectionVerifier::REFRESH_TIMEOUT_SECONDS + ConnectionVerifier::REFRESH_CONNECT_TIMEOUT_SECONDS);

    expect(ConnectionVerifier::REFRESH_LOCK_SECONDS)->toBeGreaterThan($worstCaseSeconds);
});

test('a rejected Instagram extension disconnects loudly instead of waiting for the token to die', function () {
    Queue::fake();

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'status' => Status::Connected,
        'access_token' => 'still-valid-but-unextendable',
        'token_expires_at' => now()->addHours(20),
    ]);

    Http::fake([
        config('trypost.platforms.instagram.auth_api').'/refresh_access_token*' => Http::response([
            'error' => ['message' => 'Invalid OAuth access token', 'type' => 'OAuthException', 'code' => 190],
        ], 400),
        config('trypost.platforms.instagram.graph_api').'/me*' => Http::response(['id' => '1', 'username' => 'u'], 200),
    ]);

    (new RefreshSocialToken($account))->handle(app(ConnectionVerifier::class));

    // Instagram/Threads tokens cannot be refreshed once expired. Staying
    // Connected because the token still reads means the owner is told only
    // after it dies — by which point reconnecting is the only option left.
    expect($account->fresh()->status)->toBe(Status::TokenExpired);
    Queue::assertPushed(SendNotification::class);
});

test('the job survives the account being deleted while it is in flight', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $account = $this->account;
    $account->update(['token_expires_at' => now()->addMinutes(20)]);

    SocialAccount::whereKey($account->id)->delete();

    // Guard the repro itself: a delete that silently did nothing would make
    // this test pass without ever exercising the path it claims to cover.
    expect(SocialAccount::find($account->id))->toBeNull();

    // tries = 1, so an escaping exception lands the job straight in failed_jobs.
    (new RefreshSocialToken($account))->handle(app(ConnectionVerifier::class));

    // Reaching this line is the point: the refresh ran and the vanished row
    // did not escape as a ModelNotFoundException.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth2/token'));
});

test('a 200 without a token leaves the working credential intact', function () {
    Queue::fake();

    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => '',
            'refresh_token' => 'rt-new',
            'expires_in' => 7200,
        ], 200),
    ]);

    $this->account->update([
        'access_token' => 'the-token-that-still-works',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // Persisting the empty token would destroy a credential that still works,
    // and no amount of after-the-fact detection gets it back.
    expect($this->account->fresh()->access_token)->toBe('the-token-that-still-works');

    // And it must not disconnect: the refresh_token is probably fine, so the
    // next tick should retry rather than emailing the owner to reconnect.
    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

test('refreshToken reports false for a platform with nothing to refresh', function () {
    $account = SocialAccount::factory()->mastodon()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
    ]);

    expect(app(ConnectionVerifier::class)->refreshToken($account))->toBeFalse();
});

test('every platform that claims a refresh flow actually performs one', function () {
    $verifier = app(ConnectionVerifier::class);
    $checked = 0;

    foreach (Platform::cases() as $platform) {
        if (! $platform->hasTokenRefreshFlow()) {
            continue;
        }

        $checked++;

        $account = SocialAccount::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'platform' => $platform,
            'status' => Status::Connected,
            'refresh_token' => 'rt-seed',
            'meta' => ['service' => 'https://bsky.social', 'identifier' => 'a.bsky.social'],
        ]);

        Http::fake(['*' => Http::response([
            'access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600,
            'accessJwt' => 'j', 'refreshJwt' => 'r', 'id' => '1', 'data' => ['id' => '1'],
        ], 200)]);

        try {
            $verifier->refreshToken($account);
        } catch (UnhandledMatchError $e) {
            // refreshToken()'s match has no default arm, so a platform added to
            // hasTokenRefreshFlow() without one blows up in production instead
            // of falling through quietly.
            $this->fail("{$platform->value} claims a refresh flow but refreshToken() has no arm for it");
        } catch (Throwable $e) {
            $this->fail("{$platform->value} refresh threw ".$e::class.': '.$e->getMessage());
        }

        // A broken client chain (a renamed helper, a method that no longer
        // exists on PendingRequest) raises before anything leaves the process,
        // so "no request sent" is the signal that catches it.
        expect(Http::recorded())
            ->not->toBeEmpty("{$platform->value} refresh sent no HTTP request at all");
    }

    expect($checked)->toBeGreaterThan(0);
});

test('no platform lets a tokenless 200 destroy the credential it already had', function () {
    Queue::fake();

    $verifier = app(ConnectionVerifier::class);
    $checked = 0;

    foreach (Platform::cases() as $platform) {
        if (! $platform->hasTokenRefreshFlow()) {
            continue;
        }

        $checked++;

        $account = SocialAccount::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'platform' => $platform,
            'status' => Status::Connected,
            'access_token' => 'the-token-that-still-works',
            'refresh_token' => 'rt-seed',
            'meta' => ['service' => 'https://bsky.social', 'identifier' => 'a.bsky.social'],
        ]);

        // A 200 carrying no token at all. Every provider reads a different
        // field name, so this is the shape none of them can parse.
        Http::fake(['*' => Http::response(['expires_in' => 3600], 200)]);

        try {
            $verifier->refreshToken($account);
            $this->fail("{$platform->value} accepted a 200 with no token in it");
        } catch (PlatformUnavailableException) {
            // Correct: nothing is provably dead, so refuse and let the next
            // tick retry rather than disconnecting anyone.
        } catch (Throwable $e) {
            // Without the guard the write reaches the database and trips the
            // NOT NULL column, which also poisons the surrounding transaction.
            $this->fail("{$platform->value} should refuse a tokenless 200 cleanly, got ".$e::class.': '.$e->getMessage());
        }

        expect($account->fresh()->access_token)
            ->toBe('the-token-that-still-works', "{$platform->value} overwrote a working token with nothing");
    }

    Queue::assertNotPushed(SendNotification::class);
    expect($checked)->toBeGreaterThan(0);
});

test('a platform outage never disconnects, not even once the token has expired', function () {
    Queue::fake();

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('refreshToken')->once()->andThrow(
        // TokenRefreshClient raises this for 5xx, 429 and connection timeouts.
        new PlatformUnavailableException('X API returned 429 during token refresh', 429)
    );
    app()->instance(ConnectionVerifier::class, $verifier);

    $this->account->update(['token_expires_at' => now()->subMinutes(5)]);

    (new RefreshSocialToken($this->account))->handle($verifier);

    // A rate limit around expiry is not evidence of anything. Disconnecting
    // here emails the owner and hard-fails every scheduled post, and only the
    // daily sweep would undo it.
    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

test('a platform outage on a live token stays quiet and retries', function () {
    Queue::fake();

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('refreshToken')->once()->andThrow(
        new PlatformUnavailableException('X API returned 503 during token refresh', 503)
    );
    app()->instance(ConnectionVerifier::class, $verifier);

    $this->account->update(['token_expires_at' => now()->addMinutes(20)]);

    (new RefreshSocialToken($this->account))->handle($verifier);

    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

test('a failure the fallback cannot attribute to the token surfaces instead of passing as healthy', function () {
    Queue::fake();

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('refreshToken')->once()->andThrow(new TokenExpiredException('rejected'));
    // e.g. a decrypt failure after an APP_KEY rotation, or an unhandled match
    // for a platform someone just added.
    $verifier->shouldReceive('verifyAccessToken')->once()->andThrow(new RuntimeException('cannot decrypt'));
    app()->instance(ConnectionVerifier::class, $verifier);

    // Swallowing this would leave the account Connected forever while every
    // publish hard-fails. It has to reach failed_jobs where someone sees it —
    // and it must not disconnect users, since an APP_KEY rotation breaks all
    // of them at once.
    expect(fn () => (new RefreshSocialToken($this->account))->handle($verifier))
        ->toThrow(RuntimeException::class);

    expect($this->account->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendNotification::class);
});

test('a null refresh_token in a 200 does not wipe the one we already had', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'fresh-access-token',
            'refresh_token' => null,
            'expires_in' => 7200,
        ], 200),
    ]);

    $this->account->update([
        'refresh_token' => 'the-refresh-token-that-still-works',
        'token_expires_at' => now()->addMinutes(20),
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // data_get() only falls back when the key is absent, so an explicit null
    // overwrites. Losing it means the next tick throws "no refresh token"
    // without a single call, and the account dies with the access token.
    expect($this->account->fresh()->refresh_token)->toBe('the-refresh-token-that-still-works');
});

test('a refresh already in flight on a dead token is transient, not something to publish through', function () {
    $this->account->update(['token_expires_at' => now()->subMinutes(5)]);

    Cache::lock("token_refresh:{$this->account->id}", 120)->get();

    // Returning false here hands the caller a token it already knows is dead.
    // A publisher then posts with it, gets a 401, and PublishToSocialPlatform
    // finalises the post as failed and disconnects the account — for a lock
    // that a worker death left behind.
    expect(fn () => app(ConnectionVerifier::class)->refreshToken($this->account))
        ->toThrow(PlatformUnavailableException::class);
});

test('a billed fallback check counts as a verification like any other', function () {
    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response(['error' => 'invalid_grant'], 400),
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $this->account->update([
        'access_token' => 'still-valid-access-token',
        'token_expires_at' => now()->addMinutes(20),
        'last_verified_at' => null,
    ]);

    (new RefreshSocialToken($this->account))->handle(app(ConnectionVerifier::class));

    // GET /2/users/me is billed and it just proved the token alive. Throwing
    // that away means the pre-publish check pays to ask again minutes later.
    expect($this->account->fresh()->last_verified_at)->not->toBeNull();
});

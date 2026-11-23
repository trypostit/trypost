<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Uri;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

test('threads connect redirects to oauth', function () {
    $response = $this->actingAs($this->user)
        ->get(route('app.social.threads.connect'));

    expect($response->headers->get('Location'))
        ->toStartWith(config('trypost.platforms.threads.oauth_url').'/oauth/authorize');

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
    expect(session('threads_oauth_state'))->not->toBeNull();
});

test('threads oauth callback creates account', function () {
    $state = bin2hex(random_bytes(16));

    startSocialConnect($this->workspace->id, Platform::Threads);
    session([
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        config('trypost.platforms.threads.auth_api').'/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-token',
            'user_id' => '123456789',
        ], 200),
        config('trypost.platforms.threads.auth_api').'/access_token*' => Http::response([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000, // 60 days
        ], 200),
        config('trypost.platforms.threads.graph_api').'/123456789*' => Http::response([
            'id' => '123456789',
            'username' => 'testuser',
            'name' => 'Test User',
            'threads_profile_picture_url' => null,
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'test-auth-code',
        'state' => $state,
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Threads));

    finishSocialConnect(Platform::Threads)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads->value,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
        'status' => Status::Connected->value,
    ]);
});

test('threads callback fails with invalid state', function () {
    startSocialConnect($this->workspace->id, Platform::Threads);
    session([
        'threads_oauth_state' => 'correct-state',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'test-auth-code',
        'state' => 'wrong-state',
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Threads));

    expect(socialConnectFailure())->toBe('invalid_state');

    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads->value,
    ]);
});

test('threads callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'test-auth-code',
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Threads));

    expect(socialConnectFailure())->toBeNull();
});

test('user can connect multiple threads accounts', function () {

    SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
    ]);

    $state = bin2hex(random_bytes(16));

    startSocialConnect($this->workspace->id, Platform::Threads);
    session([
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        config('trypost.platforms.threads.auth_api').'/oauth/access_token' => Http::response([
            'access_token' => 'new-token',
            'user_id' => '987654321',
        ], 200),
        config('trypost.platforms.threads.auth_api').'/access_token*' => Http::response([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000,
        ], 200),
        config('trypost.platforms.threads.graph_api').'/987654321*' => Http::response([
            'id' => '987654321',
            'username' => 'anotheruser',
            'name' => 'Another User',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'test-auth-code',
        'state' => $state,
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Threads));
    finishSocialConnect(Platform::Threads)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Threads)->count())->toBe(2);
});

test('threads callback handles token exchange failure', function () {
    $state = bin2hex(random_bytes(16));

    startSocialConnect($this->workspace->id, Platform::Threads);
    session([
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        config('trypost.platforms.threads.auth_api').'/oauth/access_token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The authorization code has expired.',
        ], 400),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'expired-auth-code',
        'state' => $state,
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Threads));

    expect(socialConnectFailure())->toBe('error_connecting');
});

test('threads callback fails the connect when the long-lived token exchange fails', function () {
    $state = bin2hex(random_bytes(16));

    startSocialConnect($this->workspace->id, Platform::Threads);
    session([
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        config('trypost.platforms.threads.auth_api').'/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-token',
            'user_id' => '123456789',
        ], 200),
        config('trypost.platforms.threads.auth_api').'/access_token*' => Http::response('upstream error', 503),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'test-auth-code',
        'state' => $state,
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Threads));

    expect(socialConnectFailure())->toBe('error_connecting');

    // A short-lived-only account would silently die within the hour and never be
    // picked up by the refresh cron, so the connect must not persist one.
    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads->value,
    ]);
});

test('threads callback records a 60-day expiry when the long-lived exchange omits expires_in', function () {
    $state = bin2hex(random_bytes(16));

    startSocialConnect($this->workspace->id, Platform::Threads);
    session([
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        config('trypost.platforms.threads.auth_api').'/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-token',
            'user_id' => '123456789',
        ], 200),
        config('trypost.platforms.threads.auth_api').'/access_token*' => Http::response([
            'access_token' => 'long-lived-token',
        ], 200),
        config('trypost.platforms.threads.graph_api').'/123456789*' => Http::response([
            'id' => '123456789',
            'username' => 'testuser',
            'name' => 'Test User',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'test-auth-code',
        'state' => $state,
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Threads));
    finishSocialConnect(Platform::Threads)->assertRedirect();

    $account = $this->workspace->socialAccounts()
        ->where('platform', Platform::Threads)
        ->first();

    expect($account->token_expires_at)->not->toBeNull();
    expect($account->token_expires_at->isAfter(now()->addDays(59)))->toBeTrue();
});

test('threads callback reconnects the original card', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
        'platform_user_id' => '123456789',
        'username' => 'old',
        'access_token' => 'expired-token',
        'status' => Status::TokenExpired,
    ]);

    $state = bin2hex(random_bytes(16));

    startSocialConnect($this->workspace->id, Platform::Threads, $account->id);
    session([
        'threads_oauth_state' => $state,
    ]);

    $authApi = config('trypost.platforms.threads.auth_api');
    $graphApi = config('trypost.platforms.threads.graph_api');

    Http::fake([
        "{$authApi}/oauth/access_token" => Http::response([
            'access_token' => 'short-lived-token',
            'user_id' => '123456789',
        ], 200),
        "{$authApi}/access_token*" => Http::response([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000,
        ], 200),
        "{$graphApi}/123456789*" => Http::response([
            'id' => '123456789',
            'username' => 'testuser',
            'name' => 'Test User',
            'threads_profile_picture_url' => null,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.threads.callback', ['code' => 'test-auth-code', 'state' => $state]))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));

    finishSocialConnect(Platform::Threads)->assertRedirect();

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->username)->toBe('testuser')
        ->and($account->fresh()->status)->toBe(Status::Connected);
});

test('threads reconnect that authorizes another account says so instead of connecting', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads,
        'platform_user_id' => '123456789',
        'username' => 'old',
    ]);

    $state = bin2hex(random_bytes(16));

    startSocialConnect($this->workspace->id, Platform::Threads, $account->id);
    session([
        'threads_oauth_state' => $state,
    ]);

    $authApi = config('trypost.platforms.threads.auth_api');
    $graphApi = config('trypost.platforms.threads.graph_api');

    Http::fake([
        "{$authApi}/oauth/access_token" => Http::response([
            'access_token' => 'short-lived-token',
            'user_id' => '999999999',
        ], 200),
        "{$authApi}/access_token*" => Http::response([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000,
        ], 200),
        "{$graphApi}/999999999*" => Http::response([
            'id' => '999999999',
            'username' => 'someone-else',
            'name' => 'Someone Else',
            'threads_profile_picture_url' => null,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.threads.callback', ['code' => 'test-auth-code', 'state' => $state]))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));

    expect(socialConnectFailure())->toBe('wrong_account');

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform_user_id)->toBe('123456789');
});

test('threads connect asks for the scopes the install configures', function () {
    config(['trypost.platforms.threads.scopes' => ['threads_basic', 'threads_content_publish']]);

    $location = $this->actingAs($this->user)->get(route('app.social.threads.connect'))->headers->get('Location');

    expect(Uri::of($location)->query()->get('scope'))->toBe('threads_basic,threads_content_publish');
});

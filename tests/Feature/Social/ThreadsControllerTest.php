<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

test('threads connect redirects to oauth', function () {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.threads.connect'));

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
    expect(session('threads_oauth_state'))->not->toBeNull();
});

test('threads oauth callback creates account', function () {
    $state = bin2hex(random_bytes(16));

    session([
        'social_connect_workspace' => $this->workspace->id,
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        'https://graph.threads.net/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-token',
            'user_id' => '123456789',
        ], 200),
        'https://graph.threads.net/access_token*' => Http::response([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000, // 60 days
        ], 200),
        'https://graph.threads.net/v1.0/123456789*' => Http::response([
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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads->value,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
        'status' => Status::Connected->value,
    ]);
});

test('threads callback fails with invalid state', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
        'threads_oauth_state' => 'correct-state',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'test-auth-code',
        'state' => 'wrong-state',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Invalid state. Please try again.'));

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('user can connect multiple threads accounts in self-hosted mode', function () {
    config()->set('trypost.self_hosted', true);

    SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
    ]);

    $state = bin2hex(random_bytes(16));

    session([
        'social_connect_workspace' => $this->workspace->id,
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        'https://graph.threads.net/oauth/access_token' => Http::response([
            'access_token' => 'new-token',
            'user_id' => '987654321',
        ], 200),
        'https://graph.threads.net/access_token*' => Http::response([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000,
        ], 200),
        'https://graph.threads.net/v1.0/987654321*' => Http::response([
            'id' => '987654321',
            'username' => 'anotheruser',
            'name' => 'Another User',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'test-auth-code',
        'state' => $state,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Threads)->count())->toBe(2);
});

test('threads callback handles token exchange failure', function () {
    $state = bin2hex(random_bytes(16));

    session([
        'social_connect_workspace' => $this->workspace->id,
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        'https://graph.threads.net/oauth/access_token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The authorization code has expired.',
        ], 400),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.threads.callback', [
        'code' => 'expired-auth-code',
        'state' => $state,
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Error connecting account. Please try again.'));
});

test('threads callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.self_hosted', false);

    SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'existing-threads',
    ]);

    $state = bin2hex(random_bytes(16));

    session([
        'social_connect_workspace' => $this->workspace->id,
        'threads_oauth_state' => $state,
    ]);

    Http::fake([
        'https://graph.threads.net/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-token',
            'user_id' => '123456789',
        ], 200),
        'https://graph.threads.net/access_token*' => Http::response([
            'access_token' => 'long-lived-token',
            'expires_in' => 5184000,
        ], 200),
        'https://graph.threads.net/v1.0/123456789*' => Http::response([
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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Threads)->count())->toBe(1);
});

test('threads callback fails the connect when the long-lived token exchange fails', function () {
    $state = bin2hex(random_bytes(16));

    session([
        'social_connect_workspace' => $this->workspace->id,
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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.error_connecting')));

    // A short-lived-only account would silently die within the hour and never be
    // picked up by the refresh cron, so the connect must not persist one.
    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Threads->value,
    ]);
});

test('threads callback records a 60-day expiry when the long-lived exchange omits expires_in', function () {
    $state = bin2hex(random_bytes(16));

    session([
        'social_connect_workspace' => $this->workspace->id,
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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $account = $this->workspace->socialAccounts()
        ->where('platform', Platform::Threads)
        ->first();

    expect($account->token_expires_at)->not->toBeNull();
    expect($account->token_expires_at->isAfter(now()->addDays(59)))->toBeTrue();
});

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

test('mastodon connect page can be rendered', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.connect'));

    $response->assertOk();
});

test('user can initiate mastodon oauth flow', function () {
    Http::fake([
        'https://mastodon.social/api/v1/apps' => Http::response([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'id' => '12345',
            'name' => config('app.name'),
            'redirect_uri' => route('app.social.mastodon.callback'),
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->post(route('app.social.mastodon.authorize'), [
            'instance' => 'https://mastodon.social',
        ]);

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('mastodon_instance'))->toBe('https://mastodon.social');
    expect(session('mastodon_client_id'))->toBe('test-client-id');
    expect(session('mastodon_client_secret'))->toBe('test-client-secret');
});

test('user cannot connect to invalid mastodon instance', function () {
    Http::fake([
        'https://invalid-instance.com/api/v1/apps' => Http::response([], 404),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.mastodon.authorize'), [
        'instance' => 'https://invalid-instance.com',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('instance');
});

test('mastodon oauth callback creates account', function () {
    // Setup session as if OAuth flow was initiated
    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
        'social_connect_workspace' => $this->workspace->id,
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'scope' => 'read:accounts write:statuses write:media',
            'created_at' => time(),
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '123456789',
            'username' => 'testuser',
            'acct' => 'testuser',
            'display_name' => 'Test User',
            'avatar' => null,
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'test-state',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon->value,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
        'status' => Status::Connected->value,
    ]);

    $account = SocialAccount::where('platform', Platform::Mastodon->value)->first();
    expect($account->scopes)->toBe(['read:accounts', 'write:statuses', 'write:media']);
});

test('mastodon callback fails with invalid state', function () {
    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'correct-state',
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'wrong-state',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon->value,
    ]);
});

test('mastodon callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'test-state',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('user can connect multiple mastodon accounts when multiple social accounts are allowed', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->mastodon()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
    ]);

    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
        'social_connect_workspace' => $this->workspace->id,
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '987654321',
            'username' => 'anotheruser',
            'acct' => 'anotheruser',
            'display_name' => 'Another User',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'test-state',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Mastodon)->count())->toBe(2);
});

test('mastodon callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->mastodon()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
    ]);

    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
        'social_connect_workspace' => $this->workspace->id,
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '987654321',
            'username' => 'anotheruser',
            'acct' => 'anotheruser',
            'display_name' => 'Another User',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'test-state',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Mastodon)->count())->toBe(1);
});

test('mastodon connection validates instance url', function () {
    $response = $this->actingAs($this->user)->post(route('app.social.mastodon.authorize'), [
        'instance' => 'not-a-valid-url',
    ]);

    $response->assertSessionHasErrors('instance');
});

test('mastodon works with custom instances', function () {
    Http::fake([
        'https://techhub.social/api/v1/apps' => Http::response([
            'client_id' => 'custom-client-id',
            'client_secret' => 'custom-client-secret',
            'id' => '67890',
            'name' => config('app.name'),
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->post(route('app.social.mastodon.authorize'), [
            'instance' => 'https://techhub.social',
        ]);

    $response->assertStatus(409); // Inertia::location with X-Inertia header

    expect(session('mastodon_instance'))->toBe('https://techhub.social');
});

test('mastodon callback reconnects the original card', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon,
        'platform_user_id' => '123456789',
        'username' => 'old',
        'access_token' => 'expired-token',
        'status' => Status::TokenExpired,
    ]);

    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'fresh-access-token',
            'token_type' => 'Bearer',
            'scope' => 'read:accounts write:statuses write:media',
            'created_at' => time(),
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '123456789',
            'username' => 'testuser',
            'acct' => 'testuser',
            'display_name' => 'Test User',
            'avatar' => null,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.mastodon.callback', ['code' => 'test-auth-code', 'state' => 'test-state']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', true)
            ->where('message', __('accounts.popup_callback.reconnected'))
        );

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('fresh-access-token')
        ->and($account->fresh()->username)->toBe('testuser')
        ->and($account->fresh()->status)->toBe(Status::Connected);
});

test('mastodon reconnect that authorizes another account says so instead of connecting', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon,
        'platform_user_id' => '123456789',
        'username' => 'old',
    ]);

    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'other-access-token',
            'token_type' => 'Bearer',
            'scope' => 'read:accounts write:statuses write:media',
            'created_at' => time(),
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '999999999',
            'username' => 'someone-else',
            'acct' => 'someone-else',
            'display_name' => 'Someone Else',
            'avatar' => null,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.mastodon.callback', ['code' => 'test-auth-code', 'state' => 'test-state']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.wrong_account'))
        );

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform_user_id)->toBe('123456789');
});

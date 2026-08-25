<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

test('pinterest connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.pinterest.com/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.pinterest.connect'));

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
});

test('pinterest oauth callback creates account', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('pinterest_user_123');
    $socialiteUser->shouldReceive('getNickname')->andReturn('pinner');
    $socialiteUser->shouldReceive('getName')->andReturn('Pinterest User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 2592000;
    $socialiteUser->approvedScopes = ['boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read'];

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest->value,
        'platform_user_id' => 'pinterest_user_123',
        'username' => 'pinner',
        'status' => Status::Connected->value,
    ]);
});

test('pinterest oauth callback splits space-separated approvedScopes before saving', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('pin-user-xyz');
    $socialiteUser->shouldReceive('getNickname')->andReturn('pinuser');
    $socialiteUser->shouldReceive('getName')->andReturn('Pin User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    // Pinterest's SocialiteProvider has scopeSeparator = ',' but Pinterest
    // returns the granted scopes space-separated, so the provider doesn't
    // split them and approvedScopes lands as a single-element array.
    $socialiteUser->approvedScopes = ['boards:read boards:write pins:read pins:write user_accounts:read'];

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $account = SocialAccount::where('platform_user_id', 'pin-user-xyz')->first();
    expect($account->scopes)->toEqualCanonicalizing([
        'boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read',
    ]);
});

test('pinterest callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('user can connect multiple pinterest accounts when multiple social accounts are allowed', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'pinterest_user_123',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('pinterest_user_456');
    $socialiteUser->shouldReceive('getNickname')->andReturn('anotherpinner');
    $socialiteUser->shouldReceive('getName')->andReturn('Another Pinterest User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 2592000;
    $socialiteUser->approvedScopes = ['boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read'];

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Pinterest)->count())->toBe(2);
});

test('pinterest callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'pinterest_user_123',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('pinterest_user_456');
    $socialiteUser->shouldReceive('getNickname')->andReturn('anotherpinner');
    $socialiteUser->shouldReceive('getName')->andReturn('Another Pinterest User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 2592000;
    $socialiteUser->approvedScopes = ['boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read'];

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Pinterest)->count())->toBe(1);
});

test('pinterest callback reconnects the same identity via updateOrCreate', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'pinterest_user_123',
        'username' => 'oldpinner',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('pinterest_user_123');
    $socialiteUser->shouldReceive('getNickname')->andReturn('pinner');
    $socialiteUser->shouldReceive('getName')->andReturn('Pinterest User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 2592000;
    $socialiteUser->approvedScopes = ['boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read'];

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Pinterest)->count())->toBe(1)
        ->and($this->workspace->socialAccounts()->first()->username)->toBe('pinner');
});

test('pinterest callback handles oauth errors gracefully', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Error connecting account. Please try again.'));
});

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

test('x connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://twitter.com/i/oauth2/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('x')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.x.connect'));

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
});

test('x oauth callback creates account', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789');
    $socialiteUser->shouldReceive('getNickname')->andReturn('testuser');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 7200;
    $socialiteUser->approvedScopes = ['tweet.read', 'tweet.write', 'users.read'];

    Socialite::shouldReceive('driver')
        ->with('x')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.x.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X->value,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
        'status' => Status::Connected->value,
    ]);
});

test('x callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.x.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('user can connect multiple x accounts when multiple social accounts are allowed', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('987654321');
    $socialiteUser->shouldReceive('getNickname')->andReturn('anotheruser');
    $socialiteUser->shouldReceive('getName')->andReturn('Another User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 7200;
    $socialiteUser->approvedScopes = ['tweet.read', 'tweet.write'];

    Socialite::shouldReceive('driver')
        ->with('x')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.x.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::X)->count())->toBe(2);
});

test('x callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('987654321');
    $socialiteUser->shouldReceive('getNickname')->andReturn('anotheruser');
    $socialiteUser->shouldReceive('getName')->andReturn('Another User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 7200;
    $socialiteUser->approvedScopes = ['tweet.read', 'tweet.write'];

    Socialite::shouldReceive('driver')
        ->with('x')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.x.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::X)->count())->toBe(1);
});

test('x callback reconnects the original card', function () {
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
        'username' => 'old',
        'access_token' => 'expired-token',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789');
    $socialiteUser->shouldReceive('getNickname')->andReturn('testuser');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'fresh-access-token';
    $socialiteUser->refreshToken = 'fresh-refresh-token';
    $socialiteUser->expiresIn = 7200;
    $socialiteUser->approvedScopes = ['tweet.read', 'tweet.write', 'users.read'];

    Socialite::shouldReceive('driver')
        ->with('x')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $this->actingAs($this->user)
        ->get(route('app.social.x.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', true)
            ->where('message', __('accounts.popup_callback.reconnected'))
        );

    expect($this->workspace->socialAccounts()->where('platform', Platform::X)->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('fresh-access-token')
        ->and($account->fresh()->username)->toBe('testuser');
});

test('x callback handles oauth errors gracefully', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('x')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.x.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Error connecting account. Please try again.'));
});

test('x reconnect that authorizes another account says so instead of network_taken', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'platform_user_id' => 'x-brand',
        'username' => 'brand',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('x-personal');
    $socialiteUser->shouldReceive('getNickname')->andReturn('personal');
    $socialiteUser->shouldReceive('getName')->andReturn('Personal');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'personal-token';
    $socialiteUser->refreshToken = 'personal-refresh';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')
        ->with('x')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $this->actingAs($this->user)
        ->get(route('app.social.x.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.wrong_account'))
        );

    expect($account->fresh()->platform_user_id)->toBe('x-brand');
});

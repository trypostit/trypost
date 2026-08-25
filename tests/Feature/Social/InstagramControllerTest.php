<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

test('instagram connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.instagram.com/oauth/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.instagram.connect'));

    $response->assertStatus(409);

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
});

test('instagram oauth callback creates account', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('12345678');
    $socialiteUser->shouldReceive('getNickname')->andReturn('testuser');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.instagram.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram->value,
        'platform_user_id' => '12345678',
        'username' => 'testuser',
        'status' => Status::Connected->value,
    ]);
});

test('instagram callback fails with expired session', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.instagram.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('user can connect multiple instagram accounts when multiple social accounts are allowed', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => '12345678',
        'status' => Status::Connected,
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('87654321');
    $socialiteUser->shouldReceive('getNickname')->andReturn('newuser');
    $socialiteUser->shouldReceive('getName')->andReturn('New User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.instagram.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Instagram)->count())->toBe(2);
});

test('instagram callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'existing-ig',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('12345678');
    $socialiteUser->shouldReceive('getNickname')->andReturn('testuser');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.instagram.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Instagram)->count())->toBe(1);
});

test('instagram callback handles oauth errors gracefully', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Error connecting account. Please try again.'));
});

test('instagram connect redirects to create workspace if none exists', function () {
    $this->user->update(['current_workspace_id' => null]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram.connect'));

    $response->assertRedirect(route('app.workspaces.create'));
});

test('instagram callback refuses an identity already connected via the facebook variant', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'shared-ig-id',
        'username' => 'brand',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('shared-ig-id');
    $socialiteUser->shouldReceive('getNickname')->andReturn('brand');
    $socialiteUser->shouldReceive('getName')->andReturn('Brand');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock([
            'user' => $socialiteUser,
        ]));

    $response = $this->actingAs($this->user)->get(route('app.social.instagram.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.all_connected')));

    expect($this->workspace->socialAccounts()->where('platform_user_id', 'shared-ig-id')->count())->toBe(1);
});

test('instagram callback reconnects the original card', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => '12345678',
        'username' => 'old',
        'access_token' => 'expired-token',
        'status' => Status::TokenExpired,
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('12345678');
    $socialiteUser->shouldReceive('getNickname')->andReturn('testuser');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'fresh-access-token';
    $socialiteUser->refreshToken = 'fresh-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $this->actingAs($this->user)
        ->get(route('app.social.instagram.callback'))
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

test('instagram reconnect that authorizes another account says so instead of connecting', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => '12345678',
        'username' => 'old',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('99999999');
    $socialiteUser->shouldReceive('getNickname')->andReturn('someone-else');
    $socialiteUser->shouldReceive('getName')->andReturn('Someone Else');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'other-access-token';
    $socialiteUser->refreshToken = 'other-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $this->actingAs($this->user)
        ->get(route('app.social.instagram.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.wrong_account'))
        );

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform_user_id)->toBe('12345678')
        ->and($account->fresh()->username)->toBe('old');
});

test('a connect racing another on the same network says it is busy without filing an error', function () {
    Log::spy();

    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('12345678');
    $socialiteUser->shouldReceive('getNickname')->andReturn('testuser');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')
        ->with('instagram')
        ->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $lock = Cache::lock("social_connect:{$this->workspace->id}:".Platform::Instagram->network(), 10);

    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($this->user)
            ->get(route('app.social.instagram.callback'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('success', false)
                ->where('message', __('accounts.popup_callback.busy'))
            );
    } finally {
        $lock->release();
    }

    expect($this->workspace->socialAccounts()->count())->toBe(0);

    Log::shouldNotHaveReceived('error');
});

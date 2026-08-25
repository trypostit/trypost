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

test('tiktok connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.tiktok.com/v2/auth/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('tiktok')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.tiktok.connect'));

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
});

test('tiktok oauth callback creates account', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('tiktok123');
    $socialiteUser->shouldReceive('getNickname')->andReturn('tiktoker');
    $socialiteUser->shouldReceive('getName')->andReturn('TikTok User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 86400;
    $socialiteUser->approvedScopes = ['user.info.basic', 'user.info.profile', 'video.publish'];

    $socialiteMock = Mockery::mock();
    $socialiteMock->shouldReceive('scopes')->andReturn($socialiteMock);
    $socialiteMock->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with('tiktok')
        ->andReturn($socialiteMock);

    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok->value,
        'platform_user_id' => 'tiktok123',
        'username' => 'tiktoker',
        'status' => Status::Connected->value,
    ]);
});

test('tiktok callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('user can connect multiple tiktok accounts when multiple social accounts are allowed', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'tiktok123',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('tiktok456');
    $socialiteUser->shouldReceive('getNickname')->andReturn('anothertiktoker');
    $socialiteUser->shouldReceive('getName')->andReturn('Another TikTok User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 86400;
    $socialiteUser->approvedScopes = ['user.info.basic', 'user.info.profile', 'video.publish'];

    $socialiteMock = Mockery::mock();
    $socialiteMock->shouldReceive('scopes')->andReturn($socialiteMock);
    $socialiteMock->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with('tiktok')
        ->andReturn($socialiteMock);

    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::TikTok)->count())->toBe(2);
});

test('tiktok callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'tiktok123',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('tiktok456');
    $socialiteUser->shouldReceive('getNickname')->andReturn('anothertiktoker');
    $socialiteUser->shouldReceive('getName')->andReturn('Another TikTok User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 86400;
    $socialiteUser->approvedScopes = ['user.info.basic', 'user.info.profile', 'video.publish'];

    $socialiteMock = Mockery::mock();
    $socialiteMock->shouldReceive('scopes')->andReturn($socialiteMock);
    $socialiteMock->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with('tiktok')
        ->andReturn($socialiteMock);

    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::TikTok)->count())->toBe(1);
});

test('tiktok callback handles oauth errors gracefully', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $mock = Mockery::mock();
    $mock->shouldReceive('scopes')->andReturn($mock);
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('tiktok')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Error connecting account. Please try again.'));
});

test('tiktok connect carries a reconnect id into the session', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok,
        'platform_user_id' => 'tiktok123',
    ]);

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.tiktok.com/v2/auth/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('tiktok')->andReturn($driverMock);

    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.tiktok.connect', ['reconnect' => $account->id]))
        ->assertStatus(409);

    expect(session('social_reconnect_id'))->toBe($account->id);
});

test('tiktok callback reconnects the original card', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok,
        'platform_user_id' => 'tiktok123',
        'username' => 'old',
        'access_token' => 'expired-token',
        'status' => Status::TokenExpired,
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('tiktok123');
    $socialiteUser->shouldReceive('getNickname')->andReturn('tiktoker');
    $socialiteUser->shouldReceive('getName')->andReturn('TikTok User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'fresh-access-token';
    $socialiteUser->refreshToken = 'fresh-refresh-token';
    $socialiteUser->expiresIn = 86400;
    $socialiteUser->approvedScopes = ['user.info.basic', 'user.info.profile', 'video.publish'];

    $socialiteMock = Mockery::mock();
    $socialiteMock->shouldReceive('scopes')->andReturn($socialiteMock);
    $socialiteMock->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('tiktok')->andReturn($socialiteMock);

    $this->actingAs($this->user)
        ->get(route('app.social.tiktok.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', true)
            ->where('message', __('accounts.popup_callback.reconnected'))
        );

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('fresh-access-token')
        ->and($account->fresh()->username)->toBe('tiktoker')
        ->and($account->fresh()->status)->toBe(Status::Connected);
});

test('tiktok reconnect that authorizes another account says so instead of connecting', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok,
        'platform_user_id' => 'tiktok123',
        'username' => 'old',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('tiktok999');
    $socialiteUser->shouldReceive('getNickname')->andReturn('someone-else');
    $socialiteUser->shouldReceive('getName')->andReturn('Someone Else');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'other-access-token';
    $socialiteUser->refreshToken = 'other-refresh-token';
    $socialiteUser->expiresIn = 86400;
    $socialiteUser->approvedScopes = ['user.info.basic'];

    $socialiteMock = Mockery::mock();
    $socialiteMock->shouldReceive('scopes')->andReturn($socialiteMock);
    $socialiteMock->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('tiktok')->andReturn($socialiteMock);

    $this->actingAs($this->user)
        ->get(route('app.social.tiktok.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.wrong_account'))
        );

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform_user_id)->toBe('tiktok123');
});

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

test('discord connect redirects to the oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://discord.com/api/oauth2/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('discord')->andReturn($driverMock);

    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.discord.connect'))
        ->assertStatus(409); // Inertia::location

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
});

test('discord oauth callback creates the server account', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('999000111'); // guild id
    $socialiteUser->shouldReceive('getNickname')->andReturn('My Server');
    $socialiteUser->shouldReceive('getName')->andReturn('My Server');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'discord-access-token';
    $socialiteUser->refreshToken = 'discord-refresh-token';
    $socialiteUser->expiresIn = null;
    $socialiteUser->approvedScopes = ['bot', 'identify', 'guilds'];

    Socialite::shouldReceive('driver')->with('discord')->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $response = $this->actingAs($this->user)->get(route('app.social.discord.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Discord->value,
        'platform_user_id' => '999000111',
        'status' => Status::Connected->value,
    ]);
});

test('discord callback fails gracefully when no server was authorized', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    // DiscordProvider throws when the token response carries no guild.
    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new RuntimeException('Discord authorization did not include a server.'));

    Socialite::shouldReceive('driver')->with('discord')->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.discord.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Discord)->count())->toBe(0);
});

test('user can connect multiple discord accounts when multiple social accounts are allowed', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '999000111',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('888000222');
    $socialiteUser->shouldReceive('getNickname')->andReturn('Another Server');
    $socialiteUser->shouldReceive('getName')->andReturn('Another Server');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'discord-access-token';
    $socialiteUser->refreshToken = 'discord-refresh-token';
    $socialiteUser->expiresIn = null;
    $socialiteUser->approvedScopes = ['bot', 'identify', 'guilds'];

    Socialite::shouldReceive('driver')->with('discord')->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $response = $this->actingAs($this->user)->get(route('app.social.discord.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Discord)->count())->toBe(2);
});

test('discord callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '999000111',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('888000222');
    $socialiteUser->shouldReceive('getNickname')->andReturn('Another Server');
    $socialiteUser->shouldReceive('getName')->andReturn('Another Server');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'discord-access-token';
    $socialiteUser->refreshToken = 'discord-refresh-token';
    $socialiteUser->expiresIn = null;
    $socialiteUser->approvedScopes = ['bot', 'identify', 'guilds'];

    Socialite::shouldReceive('driver')->with('discord')->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $response = $this->actingAs($this->user)->get(route('app.social.discord.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Discord)->count())->toBe(1);
});

test('discord callback reconnects the original card', function () {
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '999000111',
        'username' => 'old-server',
        'access_token' => 'expired-token',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('999000111');
    $socialiteUser->shouldReceive('getNickname')->andReturn('My Server');
    $socialiteUser->shouldReceive('getName')->andReturn('My Server');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'fresh-discord-token';
    $socialiteUser->refreshToken = 'fresh-refresh-token';
    $socialiteUser->expiresIn = null;
    $socialiteUser->approvedScopes = ['bot', 'identify', 'guilds'];

    Socialite::shouldReceive('driver')->with('discord')->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $this->actingAs($this->user)
        ->get(route('app.social.discord.callback'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', true)
            ->where('message', __('accounts.popup_callback.reconnected'))
        );

    expect($this->workspace->socialAccounts()->where('platform', Platform::Discord)->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('fresh-discord-token');
});

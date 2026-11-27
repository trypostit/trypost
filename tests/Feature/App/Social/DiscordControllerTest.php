<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

test('discord authorize url asks for the bot scope and leaves the server picker open', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.discord.connect'));

    expect(urldecode((string) $response->headers->get('Location')))
        ->toStartWith('https://discord.com/api/oauth2/authorize')
        ->toContain('scope=bot identify guilds')
        ->toContain('permissions=248832')
        ->not->toContain('disable_guild_select');
});

test('discord connect redirects to the oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('with')->with([])->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://discord.com/api/oauth2/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('discord')->andReturn($driverMock);

    $this->actingAs($this->user)
        ->get(route('app.social.discord.connect'))
        ->assertRedirect('https://discord.com/api/oauth2/authorize?test=1');

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
});

test('discord oauth callback creates the server account', function () {
    startSocialConnect($this->workspace->id, Platform::Discord);

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

    $response->assertRedirect(route('app.social.connect.show', Platform::Discord));
    finishSocialConnect(Platform::Discord)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Discord->value,
        'platform_user_id' => '999000111',
        'status' => Status::Connected->value,
    ]);
});

test('discord callback fails gracefully when no server was authorized', function () {
    startSocialConnect($this->workspace->id, Platform::Discord);

    // DiscordProvider throws when the token response carries no guild.
    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new RuntimeException('Discord authorization did not include a server.'));

    Socialite::shouldReceive('driver')->with('discord')->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.discord.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Discord));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Discord)->count())->toBe(0);
});

test('user can connect multiple discord accounts', function () {

    SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '999000111',
    ]);

    startSocialConnect($this->workspace->id, Platform::Discord);

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

    $response->assertRedirect(route('app.social.connect.show', Platform::Discord));
    finishSocialConnect(Platform::Discord)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Discord)->count())->toBe(2);
});

test('discord callback reconnects the original card', function () {
    $account = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '999000111',
        'username' => 'old-server',
        'access_token' => 'expired-token',
    ]);

    startSocialConnect($this->workspace->id, Platform::Discord, $account->id);

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
        ->assertRedirect(route('app.social.connect.show', Platform::Discord));

    finishSocialConnect(Platform::Discord)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Discord)->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('fresh-discord-token');
});

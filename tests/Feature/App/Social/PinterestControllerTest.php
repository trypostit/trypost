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

test('pinterest authorize url carries the publishing scopes', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.connect'));

    expect(urldecode((string) $response->headers->get('Location')))
        ->toStartWith('https://www.pinterest.com/oauth')
        ->toContain('boards:read')
        ->toContain('pins:write');
});

test('pinterest connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('with')->with([])->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.pinterest.com/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.pinterest.connect'));

    $response->assertRedirect('https://www.pinterest.com/oauth?test=1');

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
});

test('pinterest oauth callback creates account', function () {
    startSocialConnect($this->workspace->id, Platform::Pinterest);

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

    $response->assertRedirect(route('app.social.connect.show', Platform::Pinterest));

    finishSocialConnect(Platform::Pinterest)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest->value,
        'platform_user_id' => 'pinterest_user_123',
        'username' => 'pinner',
        'status' => Status::Connected->value,
    ]);
});

function fakePinterestCallbackUser(string $id): void
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn($id);
    $socialiteUser->shouldReceive('getNickname')->andReturn('pinner');
    $socialiteUser->shouldReceive('getName')->andReturn('Pinterest User');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 2592000;
    $socialiteUser->approvedScopes = ['boards:read', 'pins:write'];

    Socialite::shouldReceive('driver')->with('pinterest')->andReturn(Mockery::mock(['user' => $socialiteUser]));
}

test('pinterest callback reports the new account id and created true on a first connect', function () {
    startSocialConnect($this->workspace->id, Platform::Pinterest);
    fakePinterestCallbackUser('pin-new-1');

    $this->actingAs($this->user)->get(route('app.social.pinterest.callback'))->assertRedirect();

    $response = finishSocialConnect(Platform::Pinterest);
    $accountId = SocialAccount::where('platform_user_id', 'pin-new-1')->value('id');

    $response->assertInertiaFlash('connectedChannel.accountId', $accountId)
        ->assertInertiaFlash('connectedChannel.created', true);
});

test('pinterest callback reports created false on a reconnect', function () {
    $account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'pin-old-1',
    ]);
    startSocialConnect($this->workspace->id, Platform::Pinterest, $account->id);
    fakePinterestCallbackUser('pin-old-1');

    $this->actingAs($this->user)->get(route('app.social.pinterest.callback'))->assertRedirect();

    finishSocialConnect(Platform::Pinterest)
        ->assertInertiaFlash('connectedChannel.accountId', $account->id)
        ->assertInertiaFlash('connectedChannel.created', false);
});

test('pinterest callback failure connects nothing and flashes no channel', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.pinterest.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Pinterest))
        ->assertInertiaFlashMissing('connectedChannel');

    $this->assertDatabaseCount('social_accounts', 0);
});

test('pinterest oauth callback splits space-separated approvedScopes before saving', function () {
    startSocialConnect($this->workspace->id, Platform::Pinterest);

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
    finishSocialConnect(Platform::Pinterest)->assertRedirect();

    $account = SocialAccount::where('platform_user_id', 'pin-user-xyz')->first();
    expect($account->scopes)->toEqualCanonicalizing([
        'boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read',
    ]);
});

test('pinterest callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Pinterest));

    expect(socialConnectFailure())->toBeNull();
});

test('user can connect multiple pinterest accounts', function () {

    SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'pinterest_user_123',
    ]);

    startSocialConnect($this->workspace->id, Platform::Pinterest);

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

    $response->assertRedirect(route('app.social.connect.show', Platform::Pinterest));
    finishSocialConnect(Platform::Pinterest)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Pinterest)->count())->toBe(2);
});

test('pinterest callback reconnects the same identity via updateOrCreate', function () {

    $account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'pinterest_user_123',
        'username' => 'oldpinner',
    ]);

    startSocialConnect($this->workspace->id, Platform::Pinterest, $account);

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

    $response->assertRedirect(route('app.social.connect.show', Platform::Pinterest));
    finishSocialConnect(Platform::Pinterest)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Pinterest)->count())->toBe(1)
        ->and($this->workspace->socialAccounts()->first()->username)->toBe('pinner');
});

test('pinterest callback handles oauth errors gracefully', function () {
    startSocialConnect($this->workspace->id, Platform::Pinterest);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('pinterest')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.pinterest.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Pinterest));

    expect(socialConnectFailure())->toBe('error_connecting');
});

<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Arr;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

test('tiktok authorize url disables auto auth', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.connect'));

    expect(urldecode((string) $response->headers->get('Location')))
        ->toStartWith('https://www.tiktok.com/v2/auth/authorize')
        ->toContain('disable_auto_auth=1');
});

test('tiktok authorize url carries the default scopes', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.connect'));

    expect(urldecode((string) $response->headers->get('Location')))
        ->toStartWith('https://www.tiktok.com/v2/auth/authorize')
        ->toContain('user.info.basic')
        ->toContain('user.info.profile')
        ->toContain('user.info.stats')
        ->toContain('video.publish')
        ->toContain('video.upload')
        ->toContain('video.list');
});

/**
 * Mock the TikTok driver, hit connect, and return the scopes the controller asked for.
 *
 * @return array<int, string>
 */
function captureTikTokConnectScopes(object $test): array
{
    $captured = [];

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')
        ->withArgs(function (array $scopes) use (&$captured) {
            $captured = $scopes;

            return true;
        })
        ->andReturnSelf();
    $driverMock->shouldReceive('with')->with(['disable_auto_auth' => 1])->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.tiktok.com/v2/auth/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('tiktok')->andReturn($driverMock);

    $test->actingAs($test->user)
        ->get(route('app.social.tiktok.connect'));

    return $captured;
}

test('tiktok connect requests scopes from config', function () {
    config(['trypost.platforms.tiktok.scopes' => ['user.info.basic', 'video.publish', 'video.upload']]);

    expect(captureTikTokConnectScopes($this))->toEqual([
        'user.info.basic',
        'video.publish',
        'video.upload',
    ]);
});

test('tiktok connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('with')->with(['disable_auto_auth' => 1])->once()->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.tiktok.com/v2/auth/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('tiktok')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.tiktok.connect'));

    $response->assertRedirect('https://www.tiktok.com/v2/auth/authorize?test=1');

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
});

test('tiktok oauth callback creates account', function () {
    startSocialConnect($this->workspace->id, Platform::TikTok);

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

    $response->assertRedirect(route('app.social.connect.show', Platform::TikTok));

    finishSocialConnect(Platform::TikTok)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok->value,
        'platform_user_id' => 'tiktok123',
        'username' => 'tiktoker',
        'status' => Status::Connected->value,
    ]);
});

test('tiktok connects using only the profile fields allowed by the token', function (?string $scope, ?string $username) {
    $token = ['access_token' => 'test-token', ...Arr::whereNotNull(['scope' => $scope])];
    $profile = ['open_id' => 'tiktok123', 'display_name' => 'TikTok User', 'avatar_large_url' => null];
    $stack = HandlerStack::create(new MockHandler([
        new GuzzleResponse(200, [], json_encode($token)),
        function (RequestInterface $request) use ($profile, $username): GuzzleResponse {
            expect($request->getUri()->getPath())->toBe('/v2/user/info/');
            parse_str($request->getUri()->getQuery(), $query);
            $fields = explode(',', $query['fields']);

            if (in_array('username', $fields, true)) {
                if ($username === null) {
                    return new GuzzleResponse(401, [], '{"error":{"code":"scope_not_authorized"}}');
                }

                $profile['username'] = $username;
            }

            return new GuzzleResponse(200, [], json_encode(['data' => ['user' => $profile]]));
        },
    ]));
    config(['services.tiktok.guzzle' => ['handler' => $stack]]);
    startSocialConnect($this->workspace, Platform::TikTok);

    $this->actingAs($this->user)->withSession(['state' => 'issued-state'])
        ->get(route('app.social.tiktok.callback', ['code' => 'code', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::TikTok));

    expect(socialConnectFailure())->toBeNull();
    assertFinishedOnChannel(finishSocialConnect(Platform::TikTok));
    $channel = SocialAccount::query()->sole();
    expect($channel->username)->toBe($username)
        ->and($channel->display_name)->toBe('TikTok User');
    if ($scope !== null) {
        expect($channel->scopes)->toBe(explode(',', $scope));
    }
})->with([
    'profile declined' => ['user.info.basic,video.publish', null],
    'profile granted' => ['user.info.basic,user.info.profile,video.publish', 'tiktoker'],
    'grant omitted after consent' => [null, 'tiktoker'],
]);

test('tiktok callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::TikTok));

    expect(socialConnectFailure())->toBeNull();
});

test('user can connect multiple tiktok accounts', function () {

    SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'tiktok123',
    ]);

    startSocialConnect($this->workspace->id, Platform::TikTok);

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

    $response->assertRedirect(route('app.social.connect.show', Platform::TikTok));
    finishSocialConnect(Platform::TikTok)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::TikTok)->count())->toBe(2);
});

test('tiktok callback handles oauth errors gracefully', function () {
    startSocialConnect($this->workspace->id, Platform::TikTok);

    $mock = Mockery::mock();
    $mock->shouldReceive('scopes')->andReturn($mock);
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('tiktok')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.tiktok.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::TikTok));

    expect(socialConnectFailure())->toBe('error_connecting');
});

test('tiktok connect carries a reconnect id into the session', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok,
        'platform_user_id' => 'tiktok123',
    ]);

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('with')->with(['disable_auto_auth' => 1])->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.tiktok.com/v2/auth/authorize?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('tiktok')->andReturn($driverMock);

    $this->actingAs($this->user)
        ->get(route('app.social.tiktok.connect', ['reconnect' => $account->id]))
        ->assertRedirect('https://www.tiktok.com/v2/auth/authorize?test=1');

    expect(PendingConnection::current()?->reconnectId())->toBe($account->id);
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

    startSocialConnect($this->workspace->id, Platform::TikTok, $account->id);

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
        ->assertRedirect(route('app.social.connect.show', Platform::TikTok));

    finishSocialConnect(Platform::TikTok)->assertRedirect();

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

    startSocialConnect($this->workspace->id, Platform::TikTok, $account->id);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('tiktok999');
    $socialiteUser->shouldReceive('getNickname')->andReturn('someone-else');
    $socialiteUser->shouldReceive('getName')->andReturn('Someone Else');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'other-access-token';
    $socialiteUser->refreshToken = 'other-refresh-token';
    $socialiteUser->expiresIn = 86400;
    $socialiteUser->approvedScopes = ['user.info.basic', 'video.publish'];

    $socialiteMock = Mockery::mock();
    $socialiteMock->shouldReceive('scopes')->andReturn($socialiteMock);
    $socialiteMock->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')->with('tiktok')->andReturn($socialiteMock);

    $this->actingAs($this->user)
        ->get(route('app.social.tiktok.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::TikTok));

    expect(socialConnectFailure())->toBe('wrong_account');

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform_user_id)->toBe('tiktok123');
});

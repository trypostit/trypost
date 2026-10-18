<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

test('facebook authorize url reopens the page selection', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.facebook.connect'));

    expect(urldecode((string) $response->headers->get('Location')))
        ->toStartWith('https://www.facebook.com/')
        ->toContain('auth_type=rerequest');
});

test('facebook connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driverMock->shouldReceive('setScopes')->andReturnSelf();
    $driverMock->shouldReceive('reRequest')->once()->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v26.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.facebook.connect'));

    $response->assertRedirect('https://www.facebook.com/v26.0/dialog/oauth?test=1');

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
});

test('facebook oauth callback creates account with single page', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page_123',
                    'name' => 'My Facebook Page',
                    'username' => 'myfbpage',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'page-access-token',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    finishSocialConnect(Platform::Facebook)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook->value,
        'platform_user_id' => 'page_123',
        'username' => 'myfbpage',
        'display_name' => 'My Facebook Page',
        'status' => Status::Connected->value,
    ]);
});

test('facebook callback redirects to page selection when multiple pages', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page_1',
                    'name' => 'Page 1',
                    'username' => 'page1',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'token-1',
                ],
                [
                    'id' => 'page_2',
                    'name' => 'Page 2',
                    'username' => 'page2',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'token-2',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    expect(PendingConnection::current()->identityKeys())->toBe(['facebook:page_1', 'facebook:page_2']);

    assertFinishedOnChannel(finishSocialConnect(Platform::Facebook, ['facebook:page_2']));

    $account = $this->workspace->socialAccounts()->sole();

    expect($account->platform_user_id)->toBe('page_2')
        ->and($account->username)->toBe('page2')
        ->and($account->access_token)->toBe('token-2')
        ->and(data_get($account->meta, 'user_token'))->toBe('test-user-token');
});

test('facebook callback fails when no pages found', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(socialConnectFailure())->toBe('no_facebook_pages');
});

test('facebook callback fails with error connecting when the first accounts request fails', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::response(['error' => ['message' => 'fail']], 400),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    expect(socialConnectFailure())->toBe('error_connecting');

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(0);
});

test('facebook callback follows accounts pagination and shows picker for pages across pages', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');
    $nextUrl = "{$graphApi}/me/accounts?access_token=test-user-token&after=cursor1&limit=100";

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 'page_1',
                        'name' => 'First Page',
                        'username' => 'first',
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'token-1',
                    ],
                ],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200)
            ->push([
                'data' => [
                    [
                        'id' => 'page_2',
                        'name' => 'Second Page',
                        'username' => 'second',
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'token-2',
                    ],
                ],
            ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    expect(PendingConnection::current()->identities())->toHaveCount(2)
        ->and(data_get(PendingConnection::current()->identities(), '0.platform_user_id'))->toBe('page_1')
        ->and(data_get(PendingConnection::current()->identities(), '1.platform_user_id'))->toBe('page_2');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/me/accounts'));
});

test('facebook callback connects authorized page when first accounts page is empty', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');
    $nextUrl = "{$graphApi}/me/accounts?access_token=test-user-token&after=cursor1&limit=100";

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200)
            ->push([
                'data' => [
                    [
                        'id' => 'page_desired',
                        'name' => 'Desired Page',
                        'username' => 'desired',
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'desired-token',
                    ],
                ],
            ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    finishSocialConnect(Platform::Facebook)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook->value,
        'platform_user_id' => 'page_desired',
        'display_name' => 'Desired Page',
    ]);
});

test('facebook callback fails without connecting when accounts pagination is incomplete', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');
    $nextUrl = "{$graphApi}/me/accounts?access_token=test-user-token&after=cursor1&limit=100";

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 'page_1',
                        'name' => 'First Page',
                        'username' => 'first',
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'token-1',
                    ],
                ],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200)
            ->push(['error' => ['message' => 'rate limit']], 400),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(socialConnectFailure())->toBe('error_connecting');

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(0);
});

test('facebook callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(socialConnectFailure())->toBeNull();
});

test('user can connect multiple facebook accounts', function () {
    SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'page_existing',
    ]);

    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_456');
    $socialiteUser->token = 'new-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page_new',
                    'name' => 'New Page',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'page-token',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    finishSocialConnect(Platform::Facebook)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(2);
});

test('facebook callback handles oauth errors gracefully', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(socialConnectFailure())->toBe('error_connecting');
});

test('facebook confirmation page without a pending connection says it expired', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::Facebook))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired')->where('identities', []));
});

test('facebook finish without a pending connection connects nothing', function () {
    $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::Facebook), ['identities' => ['facebook:page_123']])
        ->assertSessionHasErrors('identities.0');

    $this->assertDatabaseCount('social_accounts', 0);
});

test('facebook finish refuses a page id the login did not offer', function () {
    startSocialConnect($this->workspace, Platform::Facebook)->offer([
        PendingConnection::identity(Platform::Facebook, 'page_123', 'My Facebook Page', 'mypage', null, 'page', [
            'username' => 'mypage',
            'display_name' => 'My Facebook Page',
            'access_token' => 'page-access-token',
            'scopes' => ['pages_manage_posts'],
            'meta' => ['page_id' => 'page_123', 'user_id' => 'facebook_user_123', 'user_token' => 'test-user-token'],
        ]),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::Facebook), ['identities' => ['facebook:invalid_page_id']])
        ->assertSessionHasErrors('identities.0');

    $this->assertDatabaseCount('social_accounts', 0);
});

test('facebook connect remembers the reconnect account from the query string', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page_1',
    ]);

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driverMock->shouldReceive('setScopes')->andReturnSelf();
    $driverMock->shouldReceive('reRequest')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v26.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.facebook.connect', ['reconnect' => $account->id]));

    $response->assertRedirect('https://www.facebook.com/v26.0/dialog/oauth?test=1');

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id)
        ->and(PendingConnection::current()?->reconnectId())->toBe($account->id);
});

test('facebook connect ignores a reconnect id from another workspace', function () {
    $foreign = SocialAccount::factory()->create([
        'platform' => Platform::Facebook,
        'platform_user_id' => 'foreign-page',
    ]);

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driverMock->shouldReceive('setScopes')->andReturnSelf();
    $driverMock->shouldReceive('reRequest')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v26.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.connect', ['reconnect' => $foreign->id]))
        ->assertRedirect('https://www.facebook.com/v26.0/dialog/oauth?test=1');

    expect(PendingConnection::current()?->reconnectId())->toBeNull();
});

test('facebook connect ignores a reconnect id from another network', function () {
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'linkedin-member',
    ]);

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driverMock->shouldReceive('setScopes')->andReturnSelf();
    $driverMock->shouldReceive('reRequest')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v26.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.connect', ['reconnect' => $linkedin->id]))
        ->assertRedirect('https://www.facebook.com/v26.0/dialog/oauth?test=1');

    expect(PendingConnection::current()?->reconnectId())->toBeNull();
});

test('facebook reconnect keeps the original card when multiple pages are returned', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page_1',
        'username' => 'oldpage',
        'access_token' => 'expired-token',
    ]);

    startSocialConnect($this->workspace->id, Platform::Facebook, $account->id);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page_1',
                    'name' => 'Page 1',
                    'username' => 'page1',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'fresh-token',
                ],
                [
                    'id' => 'page_2',
                    'name' => 'Page 2',
                    'username' => 'page2',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'other-token',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    finishSocialConnect(Platform::Facebook)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(1);

    $account->refresh();

    expect($account->platform_user_id)->toBe('page_1')
        ->and($account->access_token)->toBe('fresh-token')
        ->and($account->username)->toBe('page1');
});

test('facebook reconnect shows page_not_found when the page is missing from graph', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page_missing',
    ]);

    startSocialConnect($this->workspace->id, Platform::Facebook, $account->id);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page_other',
                    'name' => 'Other Page',
                    'username' => 'other',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'other-token',
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(socialConnectFailure())->toBe('page_not_found');
});

test('facebook finish ignores a stored reconnect id from another network', function () {
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'linkedin-member',
    ]);

    startSocialConnect($this->workspace, Platform::Facebook, $linkedin)->offer([
        PendingConnection::identity(Platform::Facebook, 'page_123', 'My Facebook Page', 'mypage', null, 'page', [
            'username' => 'mypage',
            'display_name' => 'My Facebook Page',
            'access_token' => 'page-access-token',
            'scopes' => ['pages_manage_posts'],
            'meta' => ['page_id' => 'page_123', 'user_id' => 'facebook_user_123', 'user_token' => 'test-user-token'],
        ]),
    ]);

    $this->actingAs($this->user);

    assertFinishedOnChannel(finishSocialConnect(Platform::Facebook));

    expect($linkedin->fresh()->platform)->toBe(Platform::LinkedIn)
        ->and($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(1);
});

test('facebook confirmation page refuses a user who can no longer manage accounts', function () {
    $outsider = User::factory()->create();

    startSocialConnect($this->workspace, Platform::Facebook)->offer([
        PendingConnection::identity(Platform::Facebook, 'page_123', 'My Facebook Page', 'mypage', null, 'page', [
            'username' => 'mypage',
            'display_name' => 'My Facebook Page',
            'access_token' => 'page-access-token',
            'scopes' => ['pages_manage_posts'],
            'meta' => ['page_id' => 'page_123', 'user_id' => 'facebook_user_123', 'user_token' => 'test-user-token'],
        ]),
    ]);

    $this->actingAs($outsider)
        ->get(route('app.social.connect.show', Platform::Facebook))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'error')
            ->where('reason', __('accounts.connect.errors.workspace_not_found'))
            ->where('identities', [])
        );

    expect(PendingConnection::current()->isReady())->toBeFalse();
});

test('facebook offers a page already connected so it can be refreshed', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page-1',
    ]);

    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('fb-user');
    $socialiteUser->token = 'user-token';

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driverMock->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                ['id' => 'page-1', 'name' => 'Only Page', 'access_token' => 'page-token'],
            ],
        ], 200),
        "{$graphApi}/*" => Http::response(['id' => 'fb-user', 'name' => 'Me'], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    $this->get(route('app.social.connect.show', Platform::Facebook))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'select')
            ->where('identities.0.key', 'facebook:page-1')
            ->where('identities.0.locked', true)
        );
});

test('facebook callback connects a page the user only administers through a business portfolio', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [
                [
                    'id' => 'page_owned_by_client',
                    'name' => "Client's Page",
                    'username' => 'clientpage',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'portfolio-page-token',
                ],
            ],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    finishSocialConnect(Platform::Facebook)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook->value,
        'platform_user_id' => 'page_owned_by_client',
        'display_name' => "Client's Page",
        'status' => Status::Connected->value,
    ]);
});

test('facebook callback still reports no pages when the portfolio has none either', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    expect(socialConnectFailure())->toBe('no_facebook_pages');
});

test('facebook callback offers every portfolio page when the portfolio holds more than one', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [
                [
                    'id' => 'page_owned',
                    'name' => 'Owned Page',
                    'username' => 'owned',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'owned-token',
                ],
            ],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response([
            'data' => [
                [
                    'id' => 'page_client',
                    'name' => 'Client Page',
                    'username' => 'client',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'client-token',
                ],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    expect(PendingConnection::current()->identities())->toHaveCount(2)
        ->and(data_get(PendingConnection::current()->identities(), '0.platform_user_id'))->toBe('page_owned')
        ->and(data_get(PendingConnection::current()->identities(), '1.platform_user_id'))->toBe('page_client');

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::Facebook))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/ConnectFinish')
            ->has('identities', 2));

    finishSocialConnect(Platform::Facebook, ['facebook:page_client'])->assertRedirect();

    $account = SocialAccount::where('platform_user_id', 'page_client')->sole();

    expect($account->workspace_id)->toBe($this->workspace->id)
        ->and($account->platform)->toBe(Platform::Facebook)
        ->and($account->display_name)->toBe('Client Page')
        ->and($account->access_token)->toBe('client-token');
});

test('facebook callback merges a portfolio page with the one me/accounts already returned', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page_role',
                    'name' => 'Role Page',
                    'username' => 'role',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'role-token',
                ],
            ],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [
                [
                    'id' => 'page_portfolio',
                    'name' => 'Portfolio Page',
                    'username' => 'portfolio',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'portfolio-token',
                ],
            ],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
    expect(collect(PendingConnection::current()->identities())->pluck('platform_user_id')->all())
        ->toBe(['page_role', 'page_portfolio']);
});

test('facebook callback says the permission is missing when meta lists a page without a token', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_read_engagement', 'status' => 'declined'],
        ]], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_123', 'name' => 'My Page', 'picture' => ['data' => ['url' => null]]]],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    expect(socialConnectFailure())->toBe('pages_missing_permission');

    $this->assertDatabaseCount('social_accounts', 0);
});

test('facebook drops a scope meta reports as declined', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_manage_posts', 'status' => 'granted'],
            ['permission' => 'business_management', 'status' => 'declined'],
        ]], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [[
                'id' => 'page_123',
                'name' => 'My Page',
                'picture' => ['data' => ['url' => null]],
                'access_token' => 'page-token',
            ]],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($this->user)->get(route('app.social.facebook.callback'));
    finishSocialConnect(Platform::Facebook)->assertRedirect();

    expect(SocialAccount::where('platform_user_id', 'page_123')->sole()->scopes)
        ->toContain('pages_manage_posts')
        ->not->toContain('business_management');
});

test('facebook keeps a scope meta never mentions rather than guessing it was refused', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'public_profile', 'status' => 'granted'],
        ]], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [[
                'id' => 'page_123',
                'name' => 'My Page',
                'picture' => ['data' => ['url' => null]],
                'access_token' => 'page-token',
            ]],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($this->user)->get(route('app.social.facebook.callback'));
    finishSocialConnect(Platform::Facebook)->assertRedirect();

    expect(SocialAccount::where('platform_user_id', 'page_123')->sole()->scopes)
        ->toContain('pages_manage_posts');
});

test('facebook falls back to the requested scopes when meta will not list permissions', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['error' => ['message' => 'nope']], 500),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [[
                'id' => 'page_123',
                'name' => 'My Page',
                'picture' => ['data' => ['url' => null]],
                'access_token' => 'page-token',
            ]],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($this->user)->get(route('app.social.facebook.callback'));
    finishSocialConnect(Platform::Facebook)->assertRedirect();

    expect(SocialAccount::where('platform_user_id', 'page_123')->sole()->scopes)
        ->toContain('business_management');
});

test('facebook reconnects a card whose page is now only reachable through a portfolio', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page_portfolio',
        'access_token' => 'stale-token',
        'status' => Status::Disconnected,
    ]);

    startSocialConnect($this->workspace->id, Platform::Facebook, $account->id);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'business_management', 'status' => 'granted'],
        ]], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response(['data' => [
            [
                'id' => 'page_portfolio',
                'name' => 'Reconnected Page',
                'picture' => ['data' => ['url' => null]],
                'access_token' => 'fresh-token',
            ],
            [
                'id' => 'page_other',
                'name' => 'Someone Else',
                'picture' => ['data' => ['url' => null]],
                'access_token' => 'other-token',
            ],
        ]], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.callback'));

    finishSocialConnect(Platform::Facebook)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook->value)->count())->toBe(1);

    $account->refresh();

    expect($account->access_token)->toBe('fresh-token')
        ->and($account->display_name)->toBe('Reconnected Page')
        ->and($account->status)->toBe(Status::Connected);
});

test('facebook refuses a login that declined the permission needed to publish', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'pages_manage_posts', 'status' => 'declined'],
        ]], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.callback'));

    expect(socialConnectFailure())->toBe('publish_permission_missing');

    $this->assertDatabaseCount('social_accounts', 0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/accounts'));
});

test('facebook asks rather than auto-connecting a lone page found by an incomplete walk', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'business_management', 'status' => 'granted'],
        ]], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_1',
            'name' => 'The Only One We Saw',
            'picture' => ['data' => ['url' => null]],
            'access_token' => 'page-token',
        ]]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'error' => ['message' => 'Application request limit reached', 'code' => 4],
        ], 400),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(PendingConnection::current()->identities())->toHaveCount(1);
    $this->assertDatabaseCount('social_accounts', 0);
});

test('facebook still connects a lone page when the walk saw everything', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'business_management', 'status' => 'granted'],
        ]], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_1',
            'name' => 'The Only One',
            'picture' => ['data' => ['url' => null]],
            'access_token' => 'page-token',
        ]]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.callback'));

    finishSocialConnect(Platform::Facebook)->assertRedirect();

    $this->assertDatabaseCount('social_accounts', 1);
});

test('facebook says the walk was cut short rather than claiming there are no pages', function () {
    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'business_management', 'status' => 'granted'],
        ]], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'error' => ['message' => 'Application request limit reached', 'code' => 4],
        ], 400),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.callback'));

    expect(socialConnectFailure())->toBe('pages_read_incomplete');
});

test('facebook still offers the connected page an incomplete walk read, to refresh it', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page_taken',
    ]);

    startSocialConnect($this->workspace->id, Platform::Facebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()->shouldReceive('usingGraphVersion')->andReturnSelf()->shouldReceive('user')->andReturn($socialiteUser)->getMock());

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'business_management', 'status' => 'granted'],
        ]], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_taken',
            'name' => 'Already Connected',
            'picture' => ['data' => ['url' => null]],
            'access_token' => 'page-token',
        ]]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response(['error' => ['message' => 'busy', 'code' => 2]], 500),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(socialConnectFailure())->toBeNull()
        ->and(PendingConnection::current()->identityKeys())->toBe(['facebook:page_taken']);
});

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

test('instagram-facebook authorize url reopens the page selection', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.connect'));

    expect(urldecode((string) $response->headers->get('Location')))
        ->toStartWith('https://www.facebook.com/')
        ->toContain('auth_type=rerequest');
});

test('instagram-facebook connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driverMock->shouldReceive('setScopes')->andReturnSelf();
    $driverMock->shouldReceive('redirectUrl')->andReturnSelf();
    $driverMock->shouldReceive('reRequest')->once()->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v26.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.instagram-facebook.connect'));

    $response->assertRedirect('https://www.facebook.com/v26.0/dialog/oauth?test=1');

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
});

test('instagram-facebook callback follows accounts pagination and shows picker', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(
            Mockery::mock()
                ->shouldReceive('usingGraphVersion')->andReturnSelf()
                ->shouldReceive('redirectUrl')->andReturnSelf()
                ->shouldReceive('user')->andReturn($socialiteUser)
                ->getMock()
        );

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');
    $nextUrl = "{$graphApi}/me/accounts?access_token=test-user-token&after=cursor1&limit=100";

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'fb_user', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 'page_1',
                        'name' => 'First Page',
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'page-token-1',
                        'instagram_business_account' => ['id' => 'ig_1'],
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
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'page-token-2',
                        'instagram_business_account' => ['id' => 'ig_2'],
                    ],
                ],
            ], 200),
        "{$graphApi}/ig_1*" => Http::response([
            'username' => 'ig_one',
            'name' => 'IG One',
            'profile_picture_url' => null,
        ], 200),
        "{$graphApi}/ig_2*" => Http::response([
            'username' => 'ig_two',
            'name' => 'IG Two',
            'profile_picture_url' => null,
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));
    expect(PendingConnection::current()->identities())->toHaveCount(2)
        ->and(data_get(PendingConnection::current()->identities(), '0.platform_user_id'))->toBe('ig_1')
        ->and(data_get(PendingConnection::current()->identities(), '1.platform_user_id'))->toBe('ig_2');

    Http::assertSentCount(7); // /me + /me/permissions + 2 accounts pages + /me/businesses + 2 IG lookups
});

test('instagram-facebook callback connects page when first accounts response is empty', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(
            Mockery::mock()
                ->shouldReceive('usingGraphVersion')->andReturnSelf()
                ->shouldReceive('redirectUrl')->andReturnSelf()
                ->shouldReceive('user')->andReturn($socialiteUser)
                ->getMock()
        );

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');
    $nextUrl = "{$graphApi}/me/accounts?access_token=test-user-token&after=cursor1&limit=100";

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'fb_user', 'name' => 'User'], 200),
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
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'page-token',
                        'instagram_business_account' => ['id' => 'ig_desired'],
                    ],
                ],
            ], 200),
        "{$graphApi}/ig_desired*" => Http::response([
            'username' => 'desired_ig',
            'name' => 'Desired IG',
            'profile_picture_url' => null,
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));
    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig_desired',
        'username' => 'desired_ig',
    ]);
});

test('instagram-facebook callback still connects when the instagram profile lookup times out', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(
            Mockery::mock()
                ->shouldReceive('usingGraphVersion')->andReturnSelf()
                ->shouldReceive('redirectUrl')->andReturnSelf()
                ->shouldReceive('user')->andReturn($socialiteUser)
                ->getMock()
        );

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'fb_user', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page_1',
                    'name' => 'My Page',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'page-token',
                    'instagram_business_account' => ['id' => 'ig_1'],
                ],
            ],
        ], 200),
        "{$graphApi}/ig_1*" => Http::failedConnection(),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));
    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig_1',
    ]);

    expect($this->workspace->socialAccounts()->where('platform', Platform::InstagramFacebook)->value('username'))->toBeNull();
});

test('instagram-facebook callback skips pages without instagram across paginated results', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(
            Mockery::mock()
                ->shouldReceive('usingGraphVersion')->andReturnSelf()
                ->shouldReceive('redirectUrl')->andReturnSelf()
                ->shouldReceive('user')->andReturn($socialiteUser)
                ->getMock()
        );

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');
    $nextUrl = "{$graphApi}/me/accounts?access_token=test-user-token&after=cursor1&limit=100";

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'fb_user', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 'page_no_ig',
                        'name' => 'No IG Page',
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'page-token-1',
                    ],
                ],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200)
            ->push([
                'data' => [
                    [
                        'id' => 'page_with_ig',
                        'name' => 'With IG Page',
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'page-token-2',
                        'instagram_business_account' => ['id' => 'ig_only'],
                    ],
                ],
            ], 200),
        "{$graphApi}/ig_only*" => Http::response([
            'username' => 'only_ig',
            'name' => 'Only IG',
            'profile_picture_url' => null,
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));
    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig_only',
        'username' => 'only_ig',
    ]);
});

test('instagram-facebook callback fails without connecting when accounts pagination is incomplete', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(
            Mockery::mock()
                ->shouldReceive('usingGraphVersion')->andReturnSelf()
                ->shouldReceive('redirectUrl')->andReturnSelf()
                ->shouldReceive('user')->andReturn($socialiteUser)
                ->getMock()
        );

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');
    $nextUrl = "{$graphApi}/me/accounts?access_token=test-user-token&after=cursor1&limit=100";

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'fb_user', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [
                    [
                        'id' => 'page_1',
                        'name' => 'First Page',
                        'picture' => ['data' => ['url' => null]],
                        'access_token' => 'page-token-1',
                        'instagram_business_account' => ['id' => 'ig_1'],
                    ],
                ],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200)
            ->push(['error' => ['message' => 'rate limit']], 400),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));

    expect(socialConnectFailure())->toBe('error_connecting');

    expect($this->workspace->socialAccounts()->where('platform', Platform::InstagramFacebook)->count())->toBe(0);
});

test('instagram-facebook finish forgets the pending connection after a successful pick', function () {
    startSocialConnect($this->workspace, Platform::InstagramFacebook)->offer([
        PendingConnection::identity(Platform::InstagramFacebook, 'ig-new', 'My Biz', 'mybiz', null, 'profile', [
            'username' => 'mybiz',
            'display_name' => 'My Biz',
            'access_token' => 'page-token',
            'scopes' => ['instagram_content_publish'],
            'meta' => ['page_id' => 'page-1', 'page_name' => 'My Page'],
        ]),
    ]);

    $this->actingAs($this->user);

    assertFinishedOnChannel(finishSocialConnect(Platform::InstagramFacebook));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig-new',
        'username' => 'mybiz',
    ]);

    expect(PendingConnection::current())->toBeNull();
});

test('instagram-facebook reconnect updates the original card via connectIdentity', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'ig-old',
        'username' => 'oldbiz',
        'access_token' => 'expired-token',
    ]);

    startSocialConnect($this->workspace, Platform::InstagramFacebook, $account)->offer([
        PendingConnection::identity(Platform::InstagramFacebook, 'ig-old', 'My Biz', 'mybiz', null, 'profile', [
            'username' => 'mybiz',
            'display_name' => 'My Biz',
            'access_token' => 'fresh-token',
            'scopes' => ['instagram_content_publish'],
            'meta' => ['page_id' => 'page-1', 'page_name' => 'My Page'],
        ]),
    ]);

    $this->actingAs($this->user);

    finishSocialConnect(Platform::InstagramFacebook)->assertInertiaFlash('connectedChannel.created', false);

    $account->refresh();

    expect($this->workspace->socialAccounts()->where('platform', Platform::InstagramFacebook)->count())->toBe(1)
        ->and($account->username)->toBe('mybiz')
        ->and($account->access_token)->toBe('fresh-token');
});

test('instagram-facebook confirmation page without a pending connection says it expired', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::InstagramFacebook))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired'));
});

test('instagram-facebook callback hides an instagram already connected standalone', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig',
    ]);

    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page-1',
                    'name' => 'Shared Page',
                    'access_token' => 'page-token',
                    'instagram_business_account' => ['id' => 'shared-ig'],
                ],
            ],
        ], 200),
        "{$graphApi}/*" => Http::response([
            'id' => 'shared-ig',
            'username' => 'shared',
            'name' => 'Shared',
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.instagram-facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));

    expect(socialConnectFailure())->toBe('all_connected');

    expect($this->workspace->socialAccounts()
        ->where('platform', Platform::InstagramFacebook->value)
        ->exists())->toBeFalse()
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('instagram-facebook callback hides a standalone instagram whose login reports another id', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'app-scoped-ig',
        'meta' => ['ig_user_id' => 'shared-ig'],
    ]);

    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                [
                    'id' => 'page-1',
                    'name' => 'Shared Page',
                    'access_token' => 'page-token',
                    'instagram_business_account' => ['id' => 'shared-ig'],
                ],
            ],
        ], 200),
        "{$graphApi}/*" => Http::response([
            'id' => 'shared-ig',
            'username' => 'shared',
            'name' => 'Shared',
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.instagram-facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));

    expect(socialConnectFailure())->toBe('all_connected');

    expect($this->workspace->socialAccounts()
        ->where('platform', Platform::InstagramFacebook->value)
        ->exists())->toBeFalse()
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('instagram via facebook connects a page reached through a business portfolio', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [
                [
                    'id' => 'page_portfolio',
                    'name' => 'Portfolio Page',
                    'picture' => ['data' => ['url' => null]],
                    'access_token' => 'portfolio-page-token',
                    'instagram_business_account' => ['id' => 'ig_portfolio'],
                ],
            ],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
        "{$graphApi}/ig_portfolio*" => Http::response([
            'username' => 'portfolio_ig',
            'name' => 'Portfolio IG',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig_portfolio',
        'username' => 'portfolio_ig',
        'status' => Status::Connected->value,
    ]);
});

test('instagram via facebook describes every page in rounds without serialising them', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');
    $pages = collect(range(1, 45))->map(fn (int $n) => [
        'id' => "page_{$n}",
        'name' => "Page {$n}",
        'picture' => ['data' => ['url' => null]],
        'access_token' => "page-token-{$n}",
        'instagram_business_account' => ['id' => "ig_{$n}"],
    ])->all();

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => $pages], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/ig_*" => Http::response(['username' => 'an_account'], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));
    expect(PendingConnection::current()->identities())->toHaveCount(45);

    Http::assertSentCount(4 + 45);
});

test('instagram via facebook says the permission is missing when meta lists a page without a token', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('facebook_user_123');
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_1',
            'name' => 'Page',
            'picture' => ['data' => ['url' => null]],
            'instagram_business_account' => ['id' => 'ig_1'],
        ]]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    expect(socialConnectFailure())->toBe('pages_missing_permission');
});

test('instagram via facebook does not describe a page it is about to discard', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig_taken',
    ]);

    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [
            [
                'id' => 'page_taken',
                'name' => 'Already Connected',
                'access_token' => 'taken-token',
                'instagram_business_account' => ['id' => 'ig_taken'],
            ],
            [
                'id' => 'page_free',
                'name' => 'Still Free',
                'access_token' => 'free-token',
                'instagram_business_account' => ['id' => 'ig_free'],
            ],
        ]], 200),
        "{$graphApi}/ig_free*" => Http::response(['username' => 'free_account'], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.instagram-facebook.callback'));

    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ig_taken'));

    expect(SocialAccount::where('platform_user_id', 'ig_free')->sole()->username)->toBe('free_account');
});

test('instagram via facebook falls back to the username when meta returns a null name', function () {
    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_1',
            'name' => 'Page',
            'access_token' => 'page-token',
            'instagram_business_account' => ['id' => 'ig_1'],
        ]]], 200),
        "{$graphApi}/ig_1*" => Http::response(['username' => 'only_a_handle', 'name' => null], 200),
    ]);

    $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));
    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect();

    expect(SocialAccount::where('platform_user_id', 'ig_1')->sole()->display_name)->toBe('only_a_handle');
});

test('instagram via facebook falls back to the page name when the lookups run out of time', function () {
    config()->set('trypost.meta_page_walk_seconds', 0);

    startSocialConnect($this->workspace->id, Platform::InstagramFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_1',
            'name' => 'The Page Name',
            'access_token' => 'page-token',
            'instagram_business_account' => ['id' => 'ig_1'],
        ]]], 200),
    ]);

    $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));
    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect();

    $account = SocialAccount::where('platform_user_id', 'ig_1')->sole();

    expect($account->display_name)->toBe('The Page Name')
        ->and($account->username)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ig_1'));
});

test('a reconnect keeps the handle it had when the lookup never ran', function () {
    config()->set('trypost.meta_page_walk_seconds', 0);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'ig_1',
        'username' => 'the_handle_we_had',
        'avatar_url' => 'avatars/kept.jpg',
    ]);

    startSocialConnect($this->workspace->id, Platform::InstagramFacebook, $account->id);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page_1',
            'name' => 'The Page',
            'access_token' => 'fresh-token',
            'instagram_business_account' => ['id' => 'ig_1'],
        ]]], 200),
    ]);

    $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));
    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect();

    $account->refresh();

    expect($account->username)->toBe('the_handle_we_had')
        ->and($account->getRawOriginal('avatar_url'))->toBe('avatars/kept.jpg')
        ->and($account->access_token)->toBe('fresh-token');
});

test('reconnecting a standalone instagram card through facebook moves it to instagram via facebook', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'app-scoped-1',
        'username' => 'mybiz',
        'access_token' => 'instagram-token',
        'meta' => ['account_type' => 'BUSINESS', 'ig_user_id' => 'ig-pro-1'],
    ]);
    Http::fake();

    startSocialConnect($this->workspace, Platform::InstagramFacebook, $account)->offer([
        PendingConnection::identity(Platform::InstagramFacebook, 'ig-pro-1', 'My Biz', 'mybiz', null, 'profile', [
            'username' => 'mybiz',
            'display_name' => 'My Biz',
            'access_token' => 'page-token',
            'scopes' => ['instagram_content_publish'],
            'meta' => ['page_id' => 'page-1', 'page_name' => 'My Page'],
        ]),
    ]);

    $this->actingAs($this->user);

    finishSocialConnect(Platform::InstagramFacebook)->assertInertiaFlash('connectedChannel.created', false);

    $account->refresh();

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->platform)->toBe(Platform::InstagramFacebook)
        ->and($account->platform_user_id)->toBe('ig-pro-1')
        ->and($account->access_token)->toBe('page-token');
});

test('reconnecting through facebook still refuses another instagram account', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'app-scoped-1',
        'meta' => ['ig_user_id' => 'ig-pro-1'],
    ]);

    startSocialConnect($this->workspace, Platform::InstagramFacebook, $account)->offer([
        PendingConnection::identity(Platform::InstagramFacebook, 'ig-pro-2', 'Other', 'other', null, 'profile', [
            'username' => 'other',
            'access_token' => 'page-token',
            'scopes' => ['instagram_content_publish'],
        ]),
    ]);

    $this->actingAs($this->user);
    finishSocialConnect(Platform::InstagramFacebook)->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));

    expect(socialConnectFailure())->toBe('wrong_account')
        ->and($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform)->toBe(Platform::Instagram)
        ->and($account->fresh()->platform_user_id)->toBe('app-scoped-1');
});

test('a facebook callback reconnecting a standalone instagram card that finds another instagram account says the page is missing', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'app-scoped-1',
        'meta' => ['ig_user_id' => 'ig-pro-1'],
    ]);

    startSocialConnect($this->workspace, Platform::InstagramFacebook, $account);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page-2',
            'name' => 'Other Page',
            'access_token' => 'page-token',
            'instagram_business_account' => ['id' => 'ig-pro-2'],
        ]]], 200),
        "{$graphApi}/*" => Http::response(['id' => 'ig-pro-2', 'username' => 'other', 'name' => 'Other'], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.instagram-facebook.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::InstagramFacebook));

    expect(socialConnectFailure())->toBe('page_not_found')
        ->and($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform)->toBe(Platform::Instagram)
        ->and($account->fresh()->platform_user_id)->toBe('app-scoped-1');
});

test('a facebook callback reconnecting an instagram card connected before its account id was stored asks instagram for it', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'app-scoped-1',
        'access_token' => 'instagram-token',
        'token_expires_at' => now()->addMonth(),
        'meta' => ['account_type' => 'BUSINESS'],
    ]);

    startSocialConnect($this->workspace, Platform::InstagramFacebook, $account);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->token = 'test-user-token';

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn(Mockery::mock()
            ->shouldReceive('usingGraphVersion')->andReturnSelf()
            ->shouldReceive('redirectUrl')->andReturnSelf()
            ->shouldReceive('user')->andReturn($socialiteUser)
            ->getMock());

    $graphApi = config('trypost.platforms.instagram-facebook.graph_api');
    $instagramApi = config('trypost.platforms.instagram.graph_api');

    Http::fake([
        "{$instagramApi}/me?*" => Http::response(['id' => 'app-scoped-1', 'username' => 'mybiz', 'user_id' => 'ig-pro-1'], 200),
        "{$graphApi}/me?*" => Http::response(['id' => 'facebook_user_123', 'name' => 'User'], 200),
        "{$graphApi}/me/permissions*" => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page-1',
            'name' => 'My Page',
            'access_token' => 'page-token',
            'instagram_business_account' => ['id' => 'ig-pro-1'],
        ]]], 200),
        "{$graphApi}/ig-pro-1*" => Http::response(['id' => 'ig-pro-1', 'username' => 'mybiz', 'name' => 'My Biz'], 200),
    ]);

    $this->actingAs($this->user)->get(route('app.social.instagram-facebook.callback'));

    expect(socialConnectFailure())->toBeNull();

    finishSocialConnect(Platform::InstagramFacebook)->assertInertiaFlash('connectedChannel.created', false);

    $account->refresh();

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->platform)->toBe(Platform::InstagramFacebook)
        ->and($account->platform_user_id)->toBe('ig-pro-1')
        ->and($account->access_token)->toBe('page-token');
});

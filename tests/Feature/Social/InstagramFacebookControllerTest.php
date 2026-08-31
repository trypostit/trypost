<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

test('instagram-facebook callback follows accounts pagination and shows picker', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertRedirect(route('app.social.instagram-facebook.select-page'));
    expect(session('instagram_facebook_oauth.pages'))->toHaveCount(2)
        ->and(data_get(session('instagram_facebook_oauth.pages'), '0.ig_id'))->toBe('ig_1')
        ->and(data_get(session('instagram_facebook_oauth.pages'), '1.ig_id'))->toBe('ig_2');

    Http::assertSentCount(7); // /me + /me/permissions + 2 accounts pages + /me/businesses + 2 IG lookups
});

test('instagram-facebook callback connects page when first accounts response is empty', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig_desired',
        'username' => 'desired_ig',
    ]);
});

test('instagram-facebook callback still connects when the instagram profile lookup times out', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig_1',
    ]);

    expect($this->workspace->socialAccounts()->where('platform', Platform::InstagramFacebook)->value('username'))->toBeNull();
});

test('instagram-facebook callback skips pages without instagram across paginated results', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig_only',
        'username' => 'only_ig',
    ]);
});

test('instagram-facebook callback fails without connecting when accounts pagination is incomplete', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.error_connecting')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::InstagramFacebook)->count())->toBe(0);
});

test('instagram-facebook select skips deferred onboarding progress in self-hosted mode', function () {
    config()->set('trypost.self_hosted', true);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'instagram_facebook_oauth' => [
            'user_token' => 'user-token',
            'reconnect_id' => null,
            'pages' => [
                [
                    'page_id' => 'page-1',
                    'page_name' => 'My Page',
                    'page_access_token' => 'page-token',
                    'ig_id' => 'ig-new',
                    'ig_username' => 'mybiz',
                    'ig_described' => true,
                    'ig_name' => 'My Biz',
                    'ig_picture' => null,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.instagram-facebook.select'), [
        'page_id' => 'page-1',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', true)
        ->where('onboardingProgress', false)
    );

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig-new',
        'username' => 'mybiz',
    ]);

    // After connect the session is cleared; PopupCallback sets onboardingProgress
    // inline so Inertia does not deferred-reload this select URL into /accounts.
    $this->actingAs($this->user)
        ->get(route('app.social.instagram-facebook.select-page'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.session_expired'))
            ->where('onboardingProgress', false)
        );
});

test('instagram-facebook reconnect updates the original card via connectIdentity', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'ig-old',
        'username' => 'oldbiz',
        'access_token' => 'expired-token',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'instagram_facebook_oauth' => [
            'user_token' => 'user-token',
            'reconnect_id' => $account->id,
            'pages' => [
                [
                    'page_id' => 'page-1',
                    'page_name' => 'My Page',
                    'page_access_token' => 'fresh-token',
                    'ig_id' => 'ig-old',
                    'ig_username' => 'mybiz',
                    'ig_described' => true,
                    'ig_name' => 'My Biz',
                    'ig_picture' => null,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.instagram-facebook.select'), [
        'page_id' => 'page-1',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', true)
        ->where('message', __('accounts.popup_callback.reconnected'))
    );

    $account->refresh();

    expect($this->workspace->socialAccounts()->where('platform', Platform::InstagramFacebook)->count())->toBe(1)
        ->and($account->username)->toBe('mybiz')
        ->and($account->access_token)->toBe('fresh-token');
});

test('instagram-facebook select page returns popup callback when the session expired', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.instagram-facebook.select-page'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.session_expired'))
            ->where('onboardingProgress', false)
        );
});

test('instagram-facebook select shows network_taken when a standalone instagram is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'existing-ig',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'instagram_facebook_oauth' => [
            'user_token' => 'user-token',
            'reconnect_id' => null,
            'pages' => [
                [
                    'page_id' => 'page-1',
                    'page_name' => 'My Page',
                    'page_access_token' => 'page-token',
                    'ig_id' => 'ig-new',
                    'ig_username' => 'mybiz',
                    'ig_described' => true,
                    'ig_name' => 'My Biz',
                    'ig_picture' => null,
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.instagram-facebook.select'), [
        'page_id' => 'page-1',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    expect($this->workspace->socialAccounts()->whereIn('platform', [Platform::Instagram->value, Platform::InstagramFacebook->value])->count())->toBe(1);
});

test('instagram-facebook callback hides an instagram already connected standalone in multi-account mode', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', false)->where('message', __('accounts.popup_callback.all_connected')));

    expect($this->workspace->socialAccounts()
        ->where('platform', Platform::InstagramFacebook->value)
        ->exists())->toBeFalse()
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('instagram via facebook connects a page reached through a business portfolio', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook->value,
        'platform_user_id' => 'ig_portfolio',
        'username' => 'portfolio_ig',
        'status' => Status::Connected->value,
    ]);
});

test('instagram via facebook describes every page in rounds without serialising them', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertRedirect(route('app.social.instagram-facebook.select-page'));
    expect(session('instagram_facebook_oauth.pages'))->toHaveCount(45);

    Http::assertSentCount(4 + 45);
});

test('instagram via facebook says the permission is missing when meta lists a page without a token', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.pages_missing_permission')));
});

test('instagram via facebook does not describe a page it is about to discard', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig_taken',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->get(route('app.social.instagram-facebook.callback'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ig_taken'));

    expect(SocialAccount::where('platform_user_id', 'ig_free')->sole()->username)->toBe('free_account');
});

test('instagram via facebook falls back to the username when meta returns a null name', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

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

    expect(SocialAccount::where('platform_user_id', 'ig_1')->sole()->display_name)->toBe('only_a_handle');
});

test('instagram via facebook falls back to the page name when the lookups run out of time', function () {
    config()->set('trypost.meta_page_walk_seconds', 0);

    session(['social_connect_workspace' => $this->workspace->id]);

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

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

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

    $account->refresh();

    expect($account->username)->toBe('the_handle_we_had')
        ->and($account->getRawOriginal('avatar_url'))->toBe('avatars/kept.jpg')
        ->and($account->access_token)->toBe('fresh-token');
});

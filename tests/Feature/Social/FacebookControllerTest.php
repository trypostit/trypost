<?php

declare(strict_types=1);

use App\Actions\Onboarding\ResolveOnboardingStatus;
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

test('facebook connect redirects to oauth provider', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driverMock->shouldReceive('setScopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v25.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.facebook.connect'));

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
});

test('facebook oauth callback creates account with single page', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook->value,
        'platform_user_id' => 'page_123',
        'username' => 'myfbpage',
        'display_name' => 'My Facebook Page',
        'status' => Status::Connected->value,
    ]);
});

test('facebook callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'existing_page',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    // The duplicate was blocked by the observer — only the pre-existing account remains.
    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(1);
});

test('facebook callback redirects to page selection when multiple pages', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertRedirect(route('app.social.facebook.select-page'));
    expect(session('facebook_oauth'))->not->toBeNull();
});

test('facebook callback fails when no pages found', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'No Facebook Pages found. You need to be an admin of at least one page.'));
});

test('facebook callback fails with error connecting when the first accounts request fails', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.error_connecting'))
    );

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(0);
});

test('facebook callback follows accounts pagination and shows picker for pages across pages', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertRedirect(route('app.social.facebook.select-page'));
    expect(session('facebook_oauth.pages'))->toHaveCount(2)
        ->and(data_get(session('facebook_oauth.pages'), '0.id'))->toBe('page_1')
        ->and(data_get(session('facebook_oauth.pages'), '1.id'))->toBe('page_2');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/me/accounts'));
});

test('facebook callback connects authorized page when first accounts page is empty', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook->value,
        'platform_user_id' => 'page_desired',
        'display_name' => 'Desired Page',
    ]);
});

test('facebook callback fails without connecting when accounts pagination is incomplete', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', __('accounts.popup_callback.error_connecting')));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(0);
});

test('facebook callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('user can connect multiple facebook accounts when multiple social accounts are allowed', function () {
    config(['trypost.allow_multiple_social_accounts' => true]);

    SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'page_existing',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(2);
});

test('facebook callback handles oauth errors gracefully', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.facebook.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Error connecting account. Please try again.'));
});

test('facebook page selection creates account', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
        'facebook_oauth' => [
            'user_token' => 'test-user-token',
            'user_id' => 'facebook_user_123',
            'pages' => [
                [
                    'id' => 'page_123',
                    'name' => 'My Facebook Page',
                    'username' => 'myfbpage',
                    'picture' => null,
                    'access_token' => 'page-access-token',
                ],
                [
                    'id' => 'page_456',
                    'name' => 'Other Page',
                    'username' => 'otherpage',
                    'picture' => null,
                    'access_token' => 'other-page-token',
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.facebook.select'), [
        'page_id' => 'page_123',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', true)
        ->where('onboardingProgress', false)
    );

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook->value,
        'platform_user_id' => 'page_123',
        'username' => 'myfbpage',
    ]);

    // After connect the session is cleared; PopupCallback sets onboardingProgress
    // inline so Inertia does not deferred-reload this select URL into /accounts.
    $this->actingAs($this->user)
        ->get(route('app.social.facebook.select-page'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.session_expired'))
            ->where('onboardingProgress', false)
        );
});

test('facebook select page returns popup callback when the session expired', function () {
    // Popup stays on PopupCallback — never dump /accounts. popupCallback() sets
    // onboardingProgress inline so Inertia won't deferred-reload this URL.
    $this->actingAs($this->user)
        ->get(route('app.social.facebook.select-page'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.session_expired'))
            ->where('onboardingProgress', false)
        );
});

test('facebook popup callback overrides deferred onboarding progress for mid-activation owners', function () {
    config(['trypost.self_hosted' => false]);
    subscribeAccount($this->user->account);

    expect(app(ResolveOnboardingStatus::class)->canShowProgress($this->user->fresh()))->toBeTrue();

    // Picker page may still defer onboarding — that is fine while the OAuth session exists.
    session([
        'social_connect_workspace' => $this->workspace->id,
        'facebook_oauth' => [
            'user_token' => 'test-user-token',
            'user_id' => 'facebook_user_123',
            'pages' => [
                [
                    'id' => 'page_123',
                    'name' => 'My Facebook Page',
                    'username' => 'myfbpage',
                    'picture' => null,
                    'access_token' => 'page-access-token',
                ],
            ],
        ],
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.social.facebook.select-page'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/FacebookPageSelect')
            ->missing('onboardingProgress')
            ->has('pages', 1)
        );

    // Close/error page must force inline false so Inertia does not re-GET select-page.
    session()->forget(['facebook_oauth', 'social_connect_workspace']);

    $this->actingAs($this->user->fresh())
        ->get(route('app.social.facebook.select-page'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('onboardingProgress', false)
        );
});

test('facebook page selection fails with expired session', function () {
    // No session data

    $response = $this->actingAs($this->user)->post(route('app.social.facebook.select'), [
        'page_id' => 'page_123',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('facebook page selection fails with invalid page id', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
        'facebook_oauth' => [
            'user_token' => 'test-user-token',
            'user_id' => 'facebook_user_123',
            'pages' => [
                [
                    'id' => 'page_123',
                    'name' => 'My Facebook Page',
                    'picture' => null,
                    'access_token' => 'page-access-token',
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.facebook.select'), [
        'page_id' => 'invalid_page_id',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));
    $response->assertInertia(fn (AssertableInertia $page) => $page->where('message', 'Page not found.'));
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
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v25.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.facebook.connect', ['reconnect' => $account->id]));

    $response->assertStatus(409);

    expect(session('social_connect_workspace'))->toBe($this->workspace->id)
        ->and(session('social_reconnect_id'))->toBe($account->id);
});

test('facebook connect ignores a reconnect id from another workspace', function () {
    $foreign = SocialAccount::factory()->create([
        'platform' => Platform::Facebook,
        'platform_user_id' => 'foreign-page',
    ]);

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('usingGraphVersion')->andReturnSelf();
    $driverMock->shouldReceive('setScopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v25.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.facebook.connect', ['reconnect' => $foreign->id]))
        ->assertStatus(409);

    expect(session('social_reconnect_id'))->toBeNull();
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
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.facebook.com/v25.0/dialog/oauth?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('facebook')
        ->andReturn($driverMock);

    $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.facebook.connect', ['reconnect' => $linkedin->id]))
        ->assertStatus(409);

    expect(session('social_reconnect_id'))->toBeNull();
});

test('facebook reconnect keeps the original card when multiple pages are returned', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page_1',
        'username' => 'oldpage',
        'access_token' => 'expired-token',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

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

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', true)
        ->where('message', __('accounts.popup_callback.reconnected'))
    );

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

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

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
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.page_not_found'))
        );
});

test('facebook select ignores a stored reconnect id from another network', function () {
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'linkedin-member',
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'facebook_oauth' => [
            'user_token' => 'test-user-token',
            'user_id' => 'facebook_user_123',
            'reconnect_id' => $linkedin->id,
            'pages' => [
                [
                    'id' => 'page_123',
                    'name' => 'My Facebook Page',
                    'username' => 'mypage',
                    'picture' => null,
                    'access_token' => 'page-access-token',
                ],
            ],
        ],
    ]);

    $this->actingAs($this->user)
        ->post(route('app.social.facebook.select'), ['page_id' => 'page_123'])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($linkedin->fresh()->platform)->toBe(Platform::LinkedIn)
        ->and($this->workspace->socialAccounts()->where('platform', Platform::Facebook)->count())->toBe(1);
});

test('facebook page picker refuses a user who can no longer manage accounts', function () {
    $outsider = User::factory()->create();

    session([
        'social_connect_workspace' => $this->workspace->id,
        'facebook_oauth' => [
            'user_token' => 'user-token',
            'user_id' => 'fb-user',
            'pages' => [
                ['id' => 'page-1', 'name' => 'My Page', 'access_token' => 'page-token'],
            ],
        ],
    ]);

    $this->actingAs($outsider)
        ->get(route('app.social.facebook.select-page'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/PopupCallback')
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.workspace_not_found'))
        );
});

test('facebook says every page is connected instead of network_taken in multi-account mode', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page-1',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.all_connected'))
        );
});

test('facebook callback connects a page the user only administers through a business portfolio', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook->value,
        'platform_user_id' => 'page_owned_by_client',
        'display_name' => "Client's Page",
        'status' => Status::Connected->value,
    ]);
});

test('facebook callback still reports no pages when the portfolio has none either', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.no_facebook_pages')));
});

test('facebook callback offers every portfolio page when the portfolio holds more than one', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertRedirect(route('app.social.facebook.select-page'));
    expect(session('facebook_oauth.pages'))->toHaveCount(2)
        ->and(data_get(session('facebook_oauth.pages'), '0.id'))->toBe('page_owned')
        ->and(data_get(session('facebook_oauth.pages'), '1.id'))->toBe('page_client');

    $this->actingAs($this->user)
        ->get(route('app.social.facebook.select-page'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/FacebookPageSelect')
            ->has('pages', 2));

    $this->actingAs($this->user)
        ->post(route('app.social.facebook.select'), ['page_id' => 'page_client'])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $account = SocialAccount::where('platform_user_id', 'page_client')->sole();

    expect($account->workspace_id)->toBe($this->workspace->id)
        ->and($account->platform)->toBe(Platform::Facebook)
        ->and($account->display_name)->toBe('Client Page')
        ->and($account->access_token)->toBe('client-token');
});

test('facebook callback merges a portfolio page with the one me/accounts already returned', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertRedirect(route('app.social.facebook.select-page'));
    expect(collect(session('facebook_oauth.pages'))->pluck('id')->all())
        ->toBe(['page_role', 'page_portfolio']);
});

test('facebook callback says the permission is missing when meta lists a page without a token', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.pages_missing_permission')));

    $this->assertDatabaseCount('social_accounts', 0);
});

test('facebook drops a scope meta reports as declined', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    expect(SocialAccount::where('platform_user_id', 'page_123')->sole()->scopes)
        ->toContain('pages_manage_posts')
        ->not->toContain('business_management');
});

test('facebook keeps a scope meta never mentions rather than guessing it was refused', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    expect(SocialAccount::where('platform_user_id', 'page_123')->sole()->scopes)
        ->toContain('pages_manage_posts');
});

test('facebook falls back to the requested scopes when meta will not list permissions', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
    ]);

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

    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => $account->id,
    ]);

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
        ->get(route('app.social.facebook.callback'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::Facebook->value)->count())->toBe(1);

    $account->refresh();

    expect($account->access_token)->toBe('fresh-token')
        ->and($account->display_name)->toBe('Reconnected Page')
        ->and($account->status)->toBe(Status::Connected);
});

test('facebook refuses a login that declined the permission needed to publish', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->get(route('app.social.facebook.callback'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.publish_permission_refused')));

    $this->assertDatabaseCount('social_accounts', 0);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/accounts'));
});

test('facebook asks rather than auto-connecting a lone page found by an incomplete walk', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->assertRedirect(route('app.social.facebook.select-page'));

    expect(session('facebook_oauth.pages'))->toHaveCount(1);
    $this->assertDatabaseCount('social_accounts', 0);
});

test('facebook still connects a lone page when the walk saw everything', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->get(route('app.social.facebook.callback'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->assertDatabaseCount('social_accounts', 1);
});

test('facebook says the walk was cut short rather than claiming there are no pages', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->get(route('app.social.facebook.callback'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.pages_read_incomplete')));
});

test('facebook says the walk was cut short rather than claiming everything is connected', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page_taken',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.pages_read_incomplete')));
});

test('facebook still says the slot is taken when the walk came back short', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page_taken',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

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
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('message', __('accounts.popup_callback.network_taken')));
});

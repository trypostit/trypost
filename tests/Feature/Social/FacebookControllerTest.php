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

    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
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
    config()->set('trypost.self_hosted', false);

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

    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
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

    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
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

    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
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

test('user can connect multiple facebook accounts in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

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

    Http::fake([
        'https://graph.facebook.com/*/me/accounts*' => Http::response([
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

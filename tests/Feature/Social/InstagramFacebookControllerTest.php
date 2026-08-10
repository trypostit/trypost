<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
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

    Http::assertSentCount(5); // /me + 2 accounts pages + 2 IG lookups
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

test('instagram-facebook select connects the page in self-hosted mode', function () {
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
    config()->set('trypost.self_hosted', false);

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

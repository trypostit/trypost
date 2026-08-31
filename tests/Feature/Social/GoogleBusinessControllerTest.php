<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessPublisher;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

test('connect redirects to the google-business oauth driver', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('with')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://accounts.google.com/o/oauth2/auth?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('google-business')->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.google-business.connect'));

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
});

test('google business callback auto-connects when exactly one location exists', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('gid-1');
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')->with('google-business')->andReturn(
        Mockery::mock()->shouldReceive('user')->andReturn($socialiteUser)->getMock()
    );

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocations')->once()->with('access-token')->andReturn([
            ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
        ]);
    });

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', true)
    );

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness->value,
        'platform_user_id' => 'accounts/1/locations/2',
        'display_name' => 'Downtown Store',
        'status' => Status::Connected->value,
    ]);

    $account = $this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->first();
    expect($account->meta['location_id'])->toBe('accounts/1/locations/2')
        ->and($account->meta['account_name'])->toBe('accounts/1')
        ->and($account->meta['google_user_id'])->toBe('gid-1');
});

test('google business callback shows the location picker when multiple locations exist', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('gid-1');
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')->with('google-business')->andReturn(
        Mockery::mock()->shouldReceive('user')->andReturn($socialiteUser)->getMock()
    );

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocations')->once()->andReturn([
            ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
            ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown Store', 'address' => null],
        ]);
    });

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.callback'));

    $response->assertRedirect(route('app.social.google-business.select-location'));

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->exists())->toBeFalse();
    expect(session('google_business_oauth'))->not->toBeNull();
    expect(data_get(session('google_business_oauth'), 'access_token'))->toBe('access-token');
    expect(data_get(session('google_business_oauth'), 'locations'))->toHaveCount(2);
});

test('google business callback forwards the reconnect id into the picker session', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => 'existing-account-id',
    ]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('gid-1');
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')->with('google-business')->andReturn(
        Mockery::mock()->shouldReceive('user')->andReturn($socialiteUser)->getMock()
    );

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocations')->once()->andReturn([
            ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
            ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown Store', 'address' => null],
        ]);
    });

    $this->actingAs($this->user)->get(route('app.social.google-business.callback'))
        ->assertRedirect(route('app.social.google-business.select-location'));

    expect(data_get(session('google_business_oauth'), 'reconnect_id'))->toBe('existing-account-id');
});

test('google business callback fails when no locations are found', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('gid-1');
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')->with('google-business')->andReturn(
        Mockery::mock()->shouldReceive('user')->andReturn($socialiteUser)->getMock()
    );

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocations')->once()->andReturn([]);
    });

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.no_google_business_locations'))
    );

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->exists())->toBeFalse();
});

test('google business callback shows network_taken when the network is already connected', function () {
    config()->set('trypost.self_hosted', false);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/9/locations/9',
    ]);

    session(['social_connect_workspace' => $this->workspace->id]);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('gid-1');
    $socialiteUser->token = 'access-token';
    $socialiteUser->refreshToken = 'refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')->with('google-business')->andReturn(
        Mockery::mock()->shouldReceive('user')->andReturn($socialiteUser)->getMock()
    );

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocations')->once()->andReturn([
            ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
        ]);
    });

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.network_taken'))
    );

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(1);
});

test('google business callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.callback'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.session_expired'))
    );
});

test('select location renders the picker from the session without refetching locations', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
        'google_business_oauth' => [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'user_id' => 'gid-1',
            'locations' => [
                ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
                ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown Store', 'address' => null],
            ],
        ],
    ]);

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldNotReceive('fetchLocations');
    });

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.select-location'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/GoogleBusinessLocationSelect')
        ->has('locations', 2)
    );
});

test('select location fails for a user who cannot manage the workspace accounts', function () {
    $outsider = User::factory()->create();

    session([
        'social_connect_workspace' => $this->workspace->id,
        'google_business_oauth' => [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'user_id' => 'gid-1',
            'locations' => [
                ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
                ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown Store', 'address' => null],
            ],
        ],
    ]);

    $response = $this->actingAs($outsider)->get(route('app.social.google-business.select-location'));

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.workspace_not_found'))
    );
});

test('select creates the social account for the chosen location', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
        'google_business_oauth' => [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'user_id' => 'gid-1',
            'locations' => [
                ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
                ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown Store', 'address' => null],
            ],
        ],
    ]);

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldNotReceive('fetchLocations');
    });

    $response = $this->actingAs($this->user)
        ->post(route('app.social.google-business.select'), ['location_id' => 'accounts/1/locations/2']);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/PopupCallback')
        ->where('success', true)
    );

    $account = $this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->first();
    expect($account)->not->toBeNull()
        ->and($account->meta['location_id'])->toBe('accounts/1/locations/2')
        ->and($account->status)->toBe(Status::Connected)
        ->and(session('google_business_oauth'))->toBeNull();
});

test('select fails with an unknown location id', function () {
    session([
        'social_connect_workspace' => $this->workspace->id,
        'social_reconnect_id' => 'some-account-id',
        'google_business_oauth' => [
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_in' => 3600,
            'user_id' => 'gid-1',
            'locations' => [
                ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('app.social.google-business.select'), ['location_id' => 'accounts/1/locations/nope']);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.location_not_found'))
    );

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->exists())->toBeFalse()
        ->and(session('google_business_oauth'))->toBeNull()
        ->and(session('social_reconnect_id'))->toBeNull();
});

test('select fails with expired session', function () {
    // No session data

    $response = $this->actingAs($this->user)
        ->post(route('app.social.google-business.select'), ['location_id' => 'accounts/1/locations/2']);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.session_expired'))
    );
});

test('select reconnects an existing account when a reconnect id is present', function () {
    $existingAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
        'status' => Status::TokenExpired,
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'google_business_oauth' => [
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'user_id' => 'gid-1',
            'reconnect_id' => $existingAccount->id,
            'locations' => [
                ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('app.social.google-business.select'), ['location_id' => 'accounts/1/locations/2']);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', true)
        ->where('message', __('accounts.popup_callback.reconnected'))
    );

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(1);

    $existingAccount->refresh();
    expect($existingAccount->status)->toBe(Status::Connected)
        ->and($existingAccount->access_token)->toBe('new-access-token');
});

test('select refuses to repoint a reconnected account at a different location', function () {
    $existingAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
        'access_token' => 'the-token-that-still-works',
        'status' => Status::TokenExpired,
    ]);

    session([
        'social_connect_workspace' => $this->workspace->id,
        'google_business_oauth' => [
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'user_id' => 'gid-1',
            'reconnect_id' => $existingAccount->id,
            'locations' => [
                ['id' => 'accounts/1/locations/9', 'account_name' => 'accounts/1', 'location_name' => 'locations/9', 'title' => 'Airport Kiosk', 'address' => null],
            ],
        ],
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('app.social.google-business.select'), ['location_id' => 'accounts/1/locations/9']);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('success', false)
        ->where('message', __('accounts.popup_callback.wrong_account'))
    );

    // The card and every post scheduled against it stay on the original store.
    $existingAccount->refresh();
    expect($existingAccount->platform_user_id)->toBe('accounts/1/locations/2')
        ->and($existingAccount->access_token)->toBe('the-token-that-still-works')
        ->and($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(1);
});

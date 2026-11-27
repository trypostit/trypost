<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessPublisher;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * @param  list<array<string, mixed>>  $locations
 * @return list<array<string, mixed>>
 */
function offeredGoogleBusinessLocations(array $locations, string $accessToken, ?string $refreshToken): array
{
    return array_map(fn (array $location): array => PendingConnection::identity(
        Platform::GoogleBusiness,
        (string) data_get($location, 'id'),
        data_get($location, 'title'),
        null,
        null,
        'location',
        [
            'username' => data_get($location, 'title'),
            'display_name' => data_get($location, 'title'),
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/business.manage'],
            'meta' => [
                'location_id' => data_get($location, 'id'),
                'account_name' => data_get($location, 'account_name'),
                'location_name' => data_get($location, 'location_name'),
                'maps_uri' => data_get($location, 'maps_uri'),
                'google_user_id' => 'gid-1',
            ],
        ],
    ), $locations);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
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

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
});

test('google business callback auto-connects when exactly one location exists', function () {
    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/99', 'title' => 'Abandoned picker'],
    ], 'stale-token', 'stale-refresh'));

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
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));
    finishSocialConnect(Platform::GoogleBusiness)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness->value,
        'platform_user_id' => 'accounts/1/locations/2',
        'display_name' => 'Downtown Store',
        'status' => Status::Connected->value,
    ]);

    $account = $this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->first();
    expect($account->meta['location_id'])->toBe('accounts/1/locations/2')
        ->and($account->meta['location_name'])->toBe('locations/2')
        ->and($account->meta['account_name'])->toBe('accounts/1')
        ->and($account->meta['google_user_id'])->toBe('gid-1')
        ->and(PendingConnection::current()?->identities() ?? [])->toBe([]);
});

test('google business callback shows the location picker when multiple locations exist', function () {
    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);

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

    $response->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->exists())->toBeFalse();
    expect(PendingConnection::current()->isReady())->toBeTrue();
    expect(data_get(PendingConnection::current()->identities(), '0.attributes.access_token'))->toBe('access-token');
    expect(PendingConnection::current()->identities())->toHaveCount(2);
});

test('google business callback reconnects the original location when google returns several', function () {
    $existingAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
    ]);

    startSocialConnect($this->workspace->id, Platform::GoogleBusiness, $existingAccount->id);

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
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $this->actingAs($this->user)->get(route('app.social.google-business.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));

    finishSocialConnect(Platform::GoogleBusiness)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(1)
        ->and($existingAccount->fresh()->status)->toBe(Status::Connected);
});

test('google business callback fails when no locations are found', function () {
    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/99', 'title' => 'Abandoned picker'],
    ], 'stale-token', 'stale-refresh'));

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

    $response->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));
    expect(socialConnectFailure())->toBe('no_google_business_locations');

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->exists())->toBeFalse()
        ->and(PendingConnection::current()?->identities() ?? [])->toBe([]);
});

test('google business callback connects a second location on the same network', function () {
    config()->set('trypost.self_hosted', false);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/9/locations/9',
    ]);

    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);

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
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));
    finishSocialConnect(Platform::GoogleBusiness)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(2);
});

test('google business callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.google-business.callback'));

    $response->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));
    expect(socialConnectFailure())->toBeNull();
});

test('the confirmation page lists the stored locations without refetching them', function () {
    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
        ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown Store', 'address' => null],
    ], 'access-token', 'refresh-token'));

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldNotReceive('fetchLocations');
    });

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::GoogleBusiness))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/accounts/ConnectFinish')
            ->where('state', 'select')
            ->has('identities', 2)
            ->where('identities.1.name', 'Uptown Store')
            ->where('identities.1.type', 'location')
        );
});

test('the confirmation page refuses a user who cannot manage the workspace accounts', function () {
    $outsider = User::factory()->create();

    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
        ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown Store', 'address' => null],
    ], 'access-token', 'refresh-token'));

    $this->actingAs($outsider)
        ->get(route('app.social.connect.show', Platform::GoogleBusiness))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'error')->where('identities', []));

    expect(socialConnectFailure())->toBe('workspace_not_found');

    expect(PendingConnection::current()?->identities() ?? [])->toBe([]);
});

test('finish creates the social account for the chosen location', function () {
    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
        ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown Store', 'address' => null],
    ], 'access-token', 'refresh-token'));

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldNotReceive('fetchLocations');
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $response = $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::GoogleBusiness), ['identities' => ['google_business:accounts/1/locations/2']]);

    assertFinishedOnChannel($response);

    $account = $this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->first();
    expect($account)->not->toBeNull()
        ->and($account->meta['location_id'])->toBe('accounts/1/locations/2')
        ->and($account->meta['location_name'])->toBe('locations/2')
        ->and($account->status)->toBe(Status::Connected)
        ->and(PendingConnection::current()?->identities() ?? [])->toBe([]);
});

test('finish refuses a location the login did not offer', function () {
    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
    ], 'access-token', 'refresh-token'));

    $response = $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::GoogleBusiness), ['identities' => ['google_business:accounts/1/locations/nope']]);

    $response->assertSessionHasErrors('identities.0');

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->exists())->toBeFalse();
});

test('finish without a pending connection connects nothing', function () {
    // No session data

    $response = $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::GoogleBusiness), ['identities' => ['google_business:accounts/1/locations/2']]);

    $response->assertSessionHasErrors('identities.0');
    $this->assertDatabaseCount('social_accounts', 0);
});

test('finish reconnects an existing account when a reconnect id is present', function () {
    $existingAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
        'status' => Status::TokenExpired,
    ]);

    PendingConnection::start(Platform::GoogleBusiness, $this->workspace, $existingAccount->id, null);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
    ], 'new-access-token', 'new-refresh-token'));

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $response = $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::GoogleBusiness), ['identities' => ['google_business:accounts/1/locations/2']]);

    assertFinishedOnChannel($response);

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(1);

    $existingAccount->refresh();
    expect($existingAccount->status)->toBe(Status::Connected)
        ->and($existingAccount->access_token)->toBe('new-access-token');
});

test('finish refuses to repoint a reconnected account at a different location', function () {
    $existingAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
        'access_token' => 'the-token-that-still-works',
        'status' => Status::TokenExpired,
    ]);

    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::start(Platform::GoogleBusiness, $this->workspace, $existingAccount->id, null);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/9', 'account_name' => 'accounts/1', 'location_name' => 'locations/9', 'title' => 'Airport Kiosk', 'address' => null],
    ], 'new-access-token', 'new-refresh-token'));

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $response = $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::GoogleBusiness), ['identities' => ['google_business:accounts/1/locations/9']]);

    $response->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));
    expect(socialConnectFailure())->toBe('wrong_account');

    // The card and every post scheduled against it stay on the original store.
    $existingAccount->refresh();
    expect($existingAccount->platform_user_id)->toBe('accounts/1/locations/2')
        ->and($existingAccount->access_token)->toBe('the-token-that-still-works')
        ->and($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(1)
        ->and(PendingConnection::current()?->identities() ?? [])->toBe([]);
});

test('google business callback stores the location profile photo as the avatar', function () {
    Storage::fake();

    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);

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
            [
                'id' => 'accounts/1/locations/2',
                'account_name' => 'accounts/1',
                'location_name' => 'locations/2',
                'title' => 'Downtown Store',
                'address' => null,
            ],
        ]);
        $mock->shouldReceive('fetchLocationPhoto')->once()
            ->with('access-token', 'accounts/1/locations/2')
            ->andReturn('https://93.184.216.34/profile.jpg');
    });

    Http::fake([
        'https://93.184.216.34/profile.jpg' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->actingAs($this->user)->get(route('app.social.google-business.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));

    assertFinishedOnChannel(finishSocialConnect(Platform::GoogleBusiness));

    Http::assertSent(fn ($request) => $request->url() === 'https://93.184.216.34/profile.jpg');

    $account = $this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->first();
    expect($account->getRawOriginal('avatar_url'))->not->toBeNull();
});

test('google business connect remembers the reconnect account from the query string', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
    ]);

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('with')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://accounts.google.com/o/oauth2/auth?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('google-business')->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.google-business.connect', ['reconnect' => $account->id]));

    $response->assertStatus(409);

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id)
        ->and(PendingConnection::current()?->reconnectId())->toBe($account->id);
});

test('google business callback reconnects a single matching location', function () {
    $existingAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
        'status' => Status::TokenExpired,
    ]);

    startSocialConnect($this->workspace->id, Platform::GoogleBusiness, $existingAccount->id);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('gid-1');
    $socialiteUser->token = 'new-access-token';
    $socialiteUser->refreshToken = 'new-refresh-token';
    $socialiteUser->expiresIn = 3600;

    Socialite::shouldReceive('driver')->with('google-business')->andReturn(
        Mockery::mock()->shouldReceive('user')->andReturn($socialiteUser)->getMock()
    );

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocations')->once()->andReturn([
            ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
        ]);
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $this->actingAs($this->user)->get(route('app.social.google-business.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));

    finishSocialConnect(Platform::GoogleBusiness)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(1);
    expect($existingAccount->fresh()->status)->toBe(Status::Connected)
        ->and($existingAccount->fresh()->access_token)->toBe('new-access-token');
});

test('google business callback fails reconnect when the original location is missing', function () {
    $existingAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
        'status' => Status::TokenExpired,
    ]);

    startSocialConnect($this->workspace->id, Platform::GoogleBusiness, $existingAccount->id);

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
            ['id' => 'accounts/1/locations/9', 'account_name' => 'accounts/1', 'location_name' => 'locations/9', 'title' => 'Airport Kiosk', 'address' => null],
        ]);
    });

    $this->actingAs($this->user)->get(route('app.social.google-business.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));

    expect(socialConnectFailure())->toBe('location_not_found');

    expect($existingAccount->fresh()->status)->toBe(Status::TokenExpired)
        ->and($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->count())->toBe(1);
});

test('google business callback stores the maps uri on the account', function () {
    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);

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
            [
                'id' => 'accounts/1/locations/2',
                'account_name' => 'accounts/1',
                'location_name' => 'locations/2',
                'title' => 'Downtown Store',
                'address' => null,
                'maps_uri' => 'https://maps.google.com/?cid=123',
            ],
        ]);
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $this->actingAs($this->user)->get(route('app.social.google-business.callback'))->assertRedirect();
    finishSocialConnect(Platform::GoogleBusiness)->assertRedirect();

    $account = $this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->first();
    expect($account->meta['maps_uri'])->toBe('https://maps.google.com/?cid=123');
});

test('finish forgets oauth tokens when connecting the location throws', function () {
    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
    ], 'access-token', 'refresh-token'));

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocationPhoto')->once()->andThrow(new RuntimeException('photo failed'));
    });

    $response = $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::GoogleBusiness), ['identities' => ['google_business:accounts/1/locations/2']]);

    $response->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));
    expect(socialConnectFailure())->toBe('error_connecting');

    expect(PendingConnection::current()?->identities() ?? [])->toBe([])
        ->and($this->workspace->socialAccounts()->where('platform', Platform::GoogleBusiness)->exists())->toBeFalse();
});

test('finish keeps the existing refresh token when google omits a new one', function () {
    $existingAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::GoogleBusiness,
        'platform_user_id' => 'accounts/1/locations/2',
        'refresh_token' => 'the-refresh-token-that-still-works',
        'status' => Status::TokenExpired,
    ]);

    startSocialConnect($this->workspace->id, Platform::GoogleBusiness);
    PendingConnection::start(Platform::GoogleBusiness, $this->workspace, $existingAccount->id, null);
    PendingConnection::current()->offer(offeredGoogleBusinessLocations([
        ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown Store', 'address' => null],
    ], 'new-access-token', null));

    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocationPhoto')->once()->andReturn(null);
    });

    $response = $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::GoogleBusiness), ['identities' => ['google_business:accounts/1/locations/2']]);

    assertFinishedOnChannel($response);

    $existingAccount->refresh();
    expect($existingAccount->status)->toBe(Status::Connected)
        ->and($existingAccount->access_token)->toBe('new-access-token')
        ->and($existingAccount->refresh_token)->toBe('the-refresh-token-that-still-works')
        ->and(PendingConnection::current()?->identities() ?? [])->toBe([]);
});

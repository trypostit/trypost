<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

test('linkedin authorize url carries the member and organization scopes', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.connect'));

    expect(urldecode((string) $response->headers->get('Location')))
        ->toStartWith('https://www.linkedin.com/oauth/v2/authorization')
        ->toContain('w_member_social')
        ->toContain('rw_organization_admin');
});

test('linkedin connect redirects to oauth provider via the openid driver', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('with')->with([])->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.linkedin.com/oauth/v2/authorization?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('linkedin-openid')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->get(route('app.social.linkedin.connect'));

    $response->assertRedirect('https://www.linkedin.com/oauth/v2/authorization?test=1');

    expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id);
});

/**
 * Mock the openid driver, hit connect, and return the scopes the controller asked for.
 *
 * @return array<int, string>
 */
function captureLinkedInConnectScopes(object $test): array
{
    $captured = [];

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')
        ->withArgs(function (array $scopes) use (&$captured) {
            $captured = $scopes;

            return true;
        })
        ->andReturnSelf();
    $driverMock->shouldReceive('with')->with([])->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.linkedin.com/oauth/v2/authorization?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('linkedin-openid')->andReturn($driverMock);

    $test->actingAs($test->user)
        ->get(route('app.social.linkedin.connect'));

    return $captured;
}

test('linkedin connect requests the union of personal and organization scopes', function () {
    config(['trypost.platforms.linkedin.scopes' => ['openid', 'profile', 'email', 'w_member_social']]);
    config(['trypost.platforms.linkedin-page.scopes' => ['openid', 'profile', 'email', 'w_organization_social', 'r_organization_social', 'rw_organization_admin', 'w_member_social']]);

    expect(captureLinkedInConnectScopes($this))->toEqualCanonicalizing([
        'openid', 'profile', 'email', 'w_member_social',
        'w_organization_social', 'r_organization_social', 'rw_organization_admin',
    ]);
});

test('connect requests only personal scopes when company pages are disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => true]);
    config(['trypost.platforms.linkedin-page.enabled' => false]);
    config(['trypost.platforms.linkedin.scopes' => ['openid', 'profile', 'email', 'w_member_social']]);

    expect(captureLinkedInConnectScopes($this))->toEqualCanonicalizing([
        'openid', 'profile', 'email', 'w_member_social',
    ]);
});

test('connect requests only organization scopes when the personal profile is disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    config(['trypost.platforms.linkedin-page.enabled' => true]);
    config(['trypost.platforms.linkedin-page.scopes' => ['openid', 'w_organization_social', 'r_organization_social', 'rw_organization_admin']]);

    expect(captureLinkedInConnectScopes($this))->toEqualCanonicalizing([
        'openid', 'w_organization_social', 'r_organization_social', 'rw_organization_admin',
    ]);
});

test('connect is forbidden when both linkedin capabilities are disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    config(['trypost.platforms.linkedin-page.enabled' => false]);

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.connect'))
        ->assertForbidden();
});

test('connect redirects to workspace creation when there is no current workspace', function () {
    // The EnsureHasWorkspace middleware guards the connect routes.
    $this->user->update(['current_workspace_id' => null]);

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.connect'))
        ->assertRedirect(route('app.workspaces.create'));
});

/**
 * Fake the LinkedIn login and the organizations it administers, then run the callback.
 *
 * @param  list<array<string, mixed>>  $organizations
 * @param  list<string>  $approvedScopes
 */
function runLinkedInCallback(
    object $test,
    array $organizations = [],
    string $personId = 'person-123',
    ?string $avatar = null,
    array $approvedScopes = ['openid', 'profile', 'email', 'w_member_social', 'w_organization_social', 'rw_organization_admin'],
): void {
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn($personId);
    $socialiteUser->shouldReceive('getName')->andReturn('John Doe');
    $socialiteUser->shouldReceive('getAvatar')->andReturn($avatar);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->approvedScopes = $approvedScopes;

    Socialite::shouldReceive('driver')->with('linkedin-openid')->andReturn(Mockery::mock(['user' => $socialiteUser]));

    Http::preventStrayRequests();
    Http::fake([
        config('trypost.platforms.linkedin.api').'/v2/me*' => Http::response(['id' => $personId, 'vanityName' => 'johndoe'], 200),
        config('trypost.platforms.linkedin.api').'/v2/organizationAcls*' => Http::response([
            'elements' => array_map(fn (array $organization): array => ['organization~' => $organization], $organizations),
        ], 200),
        'https://93.184.216.34/*' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $test->actingAs($test->user)
        ->get(route('app.social.linkedin.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::LinkedIn));
}

test('linkedin callback offers the member and the organizations they administer', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company', 'vanityName' => 'testcompany']]);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin:person-123', 'linkedin-page:123456']);

    $this->get(route('app.social.connect.show', Platform::LinkedIn))
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/accounts/ConnectFinish')
            ->where('state', 'select')
            ->has('identities', 2)
            ->where('identities.0.name', 'John Doe')
            ->where('identities.0.username', 'johndoe')
            ->where('identities.0.type', 'profile')
            ->where('identities.1.name', 'Test Company')
            ->where('identities.1.type', 'page')
        );

    $this->assertDatabaseCount('social_accounts', 0);
});

test('linkedin offers only organizations with an id', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback($this, [
        ['localizedName' => 'Incomplete Company'],
        ['id' => '', 'localizedName' => 'Missing ID'],
        ['id' => 123456, 'localizedName' => 'Test Company'],
    ]);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin:person-123', 'linkedin-page:123456']);
});

test('linkedin callback offers only the member when they administer no organization', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback($this);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin:person-123']);
});

test('linkedin callback offers only identities with the granted publish scope', function (string $scope, string $identity, string $skippedEndpoint) {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    $scopes = ['openid', 'profile', 'email', 'rw_organization_admin', $scope];

    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']], approvedScopes: $scopes);

    expect(PendingConnection::current()->identityKeys())->toBe([$identity]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), $skippedEndpoint));

    assertFinishedOnChannel(finishSocialConnect(Platform::LinkedIn, [$identity]));
    expect($this->workspace->socialAccounts()->sole()->scopes)->toBe($scopes);
})->with([
    'personal profile' => ['w_member_social', 'linkedin:person-123', '/v2/organizationAcls'],
    'company page' => ['w_organization_social', 'linkedin-page:123456', '/v2/me'],
]);

test('linkedin callback refuses a grant without any publish scope', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback($this, approvedScopes: ['openid', 'profile', 'email', 'rw_organization_admin']);

    $this->get(route('app.social.connect.show', Platform::LinkedIn))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'missing_permission')
            ->where('identities', [])
        );
    Http::assertNothingSent();
    $this->assertDatabaseCount('social_accounts', 0);
});

test('linkedin uses the requested scopes when the token response does not report scopes', function () {
    config([
        'trypost.platforms.linkedin.scopes' => ['openid', 'profile', 'email', 'w_member_social'],
        'trypost.platforms.linkedin-page.scopes' => ['openid', 'profile', 'email', 'w_organization_social', 'rw_organization_admin'],
    ]);
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']], approvedScopes: ['']);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin:person-123', 'linkedin-page:123456']);
    assertFinishedOnChannel(finishSocialConnect(Platform::LinkedIn, ['linkedin-page:123456']));
    expect($this->workspace->socialAccounts()->sole()->scopes)
        ->toBe(['openid', 'profile', 'email', 'w_member_social', 'w_organization_social', 'rw_organization_admin']);
});

test('linkedin callback fails with expired session', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::LinkedIn));

    expect(PendingConnection::current())->toBeNull();
});

test('linkedin callback handles oauth errors gracefully', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));
    Socialite::shouldReceive('driver')->with('linkedin-openid')->andReturn($mock);

    $this->actingAs($this->user)
        ->get(route('app.social.linkedin.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::LinkedIn));

    expect(socialConnectFailure())->toBe('error_connecting');
});

test('linkedin leaves the member out when the personal profile is disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']]);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin-page:123456']);
});

test('linkedin does not read organizations when company pages are disabled', function () {
    config(['trypost.platforms.linkedin-page.enabled' => false]);
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']]);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin:person-123']);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'organizationAcls'));
});

test('linkedin says there is nothing to connect when the profile is disabled and no page is administered', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback($this);

    expect(socialConnectFailure())->toBe('not_linkedin_admin');
});

test('finish refuses an identity whose linkedin capability was switched off after the consent screen', function (string $disabled, string $key) {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']]);

    config(["trypost.platforms.{$disabled}.enabled" => false]);

    finishSocialConnect(Platform::LinkedIn, [$key])->assertRedirect(route('app.social.connect.show', Platform::LinkedIn));

    expect(socialConnectFailure())->toBe('error_connecting');
    $this->assertDatabaseCount('social_accounts', 0);
})->with([
    'personal profile' => ['linkedin', 'linkedin:person-123'],
    'company pages' => ['linkedin-page', 'linkedin-page:123456'],
]);

test('finishing with the member creates a linkedin account', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']]);

    assertFinishedOnChannel(finishSocialConnect(Platform::LinkedIn, ['linkedin:person-123']))
        ->assertInertiaFlash('connectedChannel.created', true);

    $account = $this->workspace->socialAccounts()->sole();

    expect($account->platform)->toBe(Platform::LinkedIn)
        ->and($account->platform_user_id)->toBe('person-123')
        ->and($account->username)->toBe('johndoe')
        ->and($account->display_name)->toBe('John Doe')
        ->and($account->access_token)->toBe('test-access-token')
        ->and($account->status)->toBe(Status::Connected);
});

test('finishing with the member downloads and stores the avatar', function () {
    Storage::fake();
    fakePublicDns();
    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this, [], 'person-123', 'https://93.184.216.34/avatar.jpg');

    finishSocialConnect(Platform::LinkedIn)->assertRedirect();

    expect($this->workspace->socialAccounts()->sole()->getRawOriginal('avatar_url'))->not->toBeNull();
    Http::assertSent(fn ($request) => $request->url() === 'https://93.184.216.34/avatar.jpg');
});

test('finishing with an organization creates a linkedin-page account acting as the member', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company', 'vanityName' => 'testcompany']]);

    assertFinishedOnChannel(finishSocialConnect(Platform::LinkedIn, ['linkedin-page:123456']));

    $account = $this->workspace->socialAccounts()->sole();

    expect($account->platform)->toBe(Platform::LinkedInPage)
        ->and($account->platform_user_id)->toBe('123456')
        ->and($account->username)->toBe('testcompany')
        ->and($account->display_name)->toBe('Test Company')
        ->and(data_get($account->meta, 'organization_id'))->toBe(123456)
        ->and(data_get($account->meta, 'admin_user_id'))->toBe('person-123')
        ->and(data_get($account->meta, 'admin_name'))->toBe('John Doe');
});

test('an organization the member does not administer cannot be finished', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']]);

    $this->post(route('app.social.connect.finish', Platform::LinkedIn), ['identities' => ['linkedin-page:999999']])
        ->assertSessionHasErrors('identities.0');

    $this->assertDatabaseCount('social_accounts', 0);
});

test('linkedin splits comma-separated approved scopes before saving', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);

    runLinkedInCallback(
        $this,
        [['id' => 123456, 'localizedName' => 'Test Company']],
        approvedScopes: ['email,openid,profile,w_member_social,w_organization_social'],
    );

    finishSocialConnect(Platform::LinkedIn, ['linkedin-page:123456'])->assertRedirect();

    expect($this->workspace->socialAccounts()->sole()->scopes)
        ->toBe(['email', 'openid', 'profile', 'w_member_social', 'w_organization_social']);
});

test('the member and several organizations can be connected in one go', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this, [
        ['id' => 111, 'localizedName' => 'Company A'],
        ['id' => 222, 'localizedName' => 'Company B'],
    ]);

    assertFinishedOnChannel(finishSocialConnect(Platform::LinkedIn));

    expect($this->workspace->socialAccounts()->where('platform', Platform::LinkedInPage)->count())->toBe(2)
        ->and($this->workspace->socialAccounts()->where('platform', Platform::LinkedIn)->count())->toBe(1);
});

test('linkedin reconnect keeps the original profile card', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'person-123',
        'access_token' => 'expired-token',
        'status' => Status::TokenExpired,
    ]);

    startSocialConnect($this->workspace, Platform::LinkedIn, $account);
    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']]);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin:person-123']);

    finishSocialConnect(Platform::LinkedIn)
        ->assertInertiaFlash('connectedChannel.accountId', $account->id)
        ->assertInertiaFlash('connectedChannel.created', false);

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('test-access-token')
        ->and($account->fresh()->status)->toBe(Status::Connected);
});

test('linkedin reconnect keeps the original page card', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedInPage,
        'platform_user_id' => '123456',
        'access_token' => 'expired-token',
    ]);

    startSocialConnect($this->workspace, Platform::LinkedIn, $account);
    runLinkedInCallback($this, [
        ['id' => 123456, 'localizedName' => 'Test Company'],
        ['id' => 222, 'localizedName' => 'Other Company'],
    ]);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin-page:123456']);

    finishSocialConnect(Platform::LinkedIn)->assertRedirect();

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('test-access-token');
});

test('linkedin reconnect requires publish permission for the original identity', function (Platform $platform, string $identityId, string $otherPublishScope) {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => $platform,
        'platform_user_id' => $identityId,
        'access_token' => 'expired-token',
        'status' => Status::TokenExpired,
    ]);
    startSocialConnect($this->workspace, Platform::LinkedIn, $account);

    runLinkedInCallback(
        $this,
        [['id' => 123456, 'localizedName' => 'Test Company']],
        approvedScopes: ['openid', 'profile', 'email', 'rw_organization_admin', $otherPublishScope],
    );

    $this->get(route('app.social.connect.show', Platform::LinkedIn))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'missing_permission')
            ->where('identities', [])
            ->where('reconnecting', true)
            ->where('retryUrl', route('app.social.linkedin.connect', [
                'reconnect' => $account->id,
                'return_to' => route('app.workspace.channels', absolute: false),
            ]))
        );
    finishSocialConnect(Platform::LinkedIn, ["{$platform->value}:{$identityId}"])
        ->assertSessionHasErrors('identities.0');
    $this->assertDatabaseCount('social_accounts', 1);
    expect($account->fresh())
        ->access_token->toBe('expired-token')
        ->status->toBe(Status::TokenExpired);
})->with([
    'personal profile' => [Platform::LinkedIn, 'person-123', 'w_organization_social'],
    'company page' => [Platform::LinkedInPage, '123456', 'w_member_social'],
]);

test('linkedin shows an organization already connected as locked', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedInPage,
        'platform_user_id' => '123456',
    ]);

    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']]);

    $this->get(route('app.social.connect.show', Platform::LinkedIn))
        ->assertInertia(fn (Assert $page) => $page
            ->where('identities.0.locked', false)
            ->where('identities.1.key', 'linkedin-page:123456')
            ->where('identities.1.locked', true)
        );
});

test('linkedin reports a wrong account when a profile reconnect authorizes another member', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'person-original',
    ]);

    startSocialConnect($this->workspace, Platform::LinkedIn, $account);
    runLinkedInCallback($this, [], 'person-other', 'https://93.184.216.34/other.jpg');

    expect(socialConnectFailure())->toBe('wrong_account')
        ->and($account->fresh()->platform_user_id)->toBe('person-original');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.jpg'));
});

test('linkedin reports a missing page when a page reconnect does not find it', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedInPage,
        'platform_user_id' => '123456',
    ]);

    startSocialConnect($this->workspace, Platform::LinkedIn, $account);
    runLinkedInCallback($this, [['id' => 999, 'localizedName' => 'Another', 'logoV2' => null]]);

    expect(socialConnectFailure())->toBe('page_not_found');
    $this->assertDatabaseCount('social_accounts', 1);
});

test('a profile reconnect cannot finish an organization and never downloads its logo', function () {
    Storage::fake();
    fakePublicDns();

    $account = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'person-123',
    ]);

    startSocialConnect($this->workspace, Platform::LinkedIn, $account);
    runLinkedInCallback($this, [['id' => 111, 'localizedName' => 'My Company', 'logoV2' => ['original~' => ['elements' => [['identifiers' => [['identifier' => 'https://93.184.216.34/logo.jpg']]]]]]]]);

    expect(PendingConnection::current()->identityKeys())->toBe(['linkedin:person-123']);

    finishSocialConnect(Platform::LinkedIn, ['linkedin-page:111'])->assertSessionHasErrors('identities.0');

    Http::assertNotSent(fn ($request) => $request->url() === 'https://93.184.216.34/logo.jpg');

    expect($account->fresh()->platform_user_id)->toBe('person-123')
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('a member already connected with no organization is offered locked and is not connected twice', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'person-123',
        'access_token' => 'old-token',
    ]);

    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this);

    $this->get(route('app.social.connect.show', Platform::LinkedIn))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'select')
            ->has('identities', 1)
            ->where('identities.0.key', 'linkedin:person-123')
            ->where('identities.0.locked', true)
        );

    finishSocialConnect(Platform::LinkedIn, ['linkedin:person-123'])->assertSessionHasErrors('identities.0');

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('old-token');
});

test('linkedin without a pending connection shows the expired page and finish connects nothing', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::LinkedIn))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state', 'expired')
            ->where('identities', [])
            ->where('retryUrl', route('app.social.linkedin.connect'))
        );

    $this->post(route('app.social.connect.finish', Platform::LinkedIn), ['identities' => ['linkedin:person-123']])
        ->assertRedirect(route('app.social.connect.show', Platform::LinkedIn));

    $this->assertDatabaseCount('social_accounts', 0);
});

test('a finished linkedin connection leaves no identities or tokens in the session', function () {
    startSocialConnect($this->workspace, Platform::LinkedIn);
    runLinkedInCallback($this, [['id' => 123456, 'localizedName' => 'Test Company']]);

    expect(json_encode(session()->all()))->toContain('test-access-token');

    finishSocialConnect(Platform::LinkedIn, ['linkedin:person-123'])->assertRedirect();

    expect(PendingConnection::current())->toBeNull()
        ->and(json_encode(session()->all()))->not->toContain('test-access-token');
});

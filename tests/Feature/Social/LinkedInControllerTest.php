<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

/**
 * Build a Socialite user for the LinkedIn person behind the OAuth grant.
 */
function linkedInSocialiteUser(string $id = 'person-123'): SocialiteUser
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn($id);
    $socialiteUser->shouldReceive('getName')->andReturn('John Doe');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'test-access-token';
    $socialiteUser->refreshToken = 'test-refresh-token';
    $socialiteUser->expiresIn = 5184000; // 60 days
    $socialiteUser->approvedScopes = ['openid', 'profile', 'email', 'w_member_social'];

    return $socialiteUser;
}

test('linkedin connect redirects to oauth provider via the openid driver', function () {
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('scopes')->andReturnSelf();
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.linkedin.com/oauth/v2/authorization?test=1',
    ]));

    Socialite::shouldReceive('driver')
        ->with('linkedin-openid')
        ->andReturn($driverMock);

    $response = $this->actingAs($this->user)
        ->withHeader('X-Inertia', 'true')
        ->get(route('app.social.linkedin.connect'));

    $response->assertStatus(409); // Inertia::location returns 409 with X-Inertia header

    expect(session('social_connect_workspace'))->toBe($this->workspace->id);
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
    $driverMock->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => 'https://www.linkedin.com/oauth/v2/authorization?test=1',
    ]));

    Socialite::shouldReceive('driver')->with('linkedin-openid')->andReturn($driverMock);

    $test->actingAs($test->user)
        ->withHeader('X-Inertia', 'true')
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

test('linkedin callback stores the person and organizations then redirects to the selector', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    Socialite::shouldReceive('driver')
        ->with('linkedin-openid')
        ->andReturn(Mockery::mock(['user' => linkedInSocialiteUser()]));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/v2/me*' => Http::response(['id' => 'person-123', 'vanityName' => 'johndoe'], 200),
        config('trypost.platforms.linkedin.api').'/v2/organizationAcls*' => Http::response([
            'elements' => [
                ['organization~' => ['id' => 123456, 'localizedName' => 'Test Company', 'vanityName' => 'testcompany']],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.callback'));

    $response->assertRedirect(route('app.social.linkedin.select-identity'));

    expect(session('linkedin_pending.person.id'))->toBe('person-123');
    expect(session('linkedin_pending.person.vanity_name'))->toBe('johndoe');
    expect(session('linkedin_pending.organizations'))->toHaveCount(1);
});

test('linkedin callback still redirects to the selector when the member administers no organizations', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    Socialite::shouldReceive('driver')
        ->with('linkedin-openid')
        ->andReturn(Mockery::mock(['user' => linkedInSocialiteUser()]));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/v2/me*' => Http::response(['vanityName' => 'johndoe'], 200),
        config('trypost.platforms.linkedin.api').'/v2/organizationAcls*' => Http::response(['elements' => []], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.callback'));

    $response->assertRedirect(route('app.social.linkedin.select-identity'));
    expect(session('linkedin_pending.organizations'))->toBe([]);
});

test('linkedin callback fails with expired session', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.callback'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
    $response->assertInertia(fn (Assert $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('linkedin callback handles oauth errors gracefully', function () {
    session(['social_connect_workspace' => $this->workspace->id]);

    $mock = Mockery::mock();
    $mock->shouldReceive('user')->andThrow(new Exception('OAuth error'));

    Socialite::shouldReceive('driver')->with('linkedin-openid')->andReturn($mock);

    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.callback'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
    $response->assertInertia(fn (Assert $page) => $page->where('message', 'Error connecting account. Please try again.'));
});

test('select-identity screen renders the person and organizations', function () {
    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'profile', 'email', 'w_member_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [
            ['id' => 123456, 'name' => 'Test Company', 'vanity_name' => 'testcompany', 'logo' => null],
        ],
    ]]);

    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.select-identity'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('accounts/LinkedInSelect')
        ->where('person.name', 'John Doe')
        ->has('organizations', 1)
    );
});

test('select-identity hides the personal profile when that capability is disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    config(['trypost.platforms.linkedin-page.enabled' => true]);

    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'w_organization_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => null],
        'organizations' => [
            ['id' => 123456, 'name' => 'Test Company', 'vanity_name' => 'testcompany', 'logo' => null],
        ],
    ]]);

    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.select-identity'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('accounts/LinkedInSelect')
        ->where('person', null)
        ->has('organizations', 1)
    );
});

test('selecting the person is rejected when the personal profile capability is disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    config(['trypost.platforms.linkedin-page.enabled' => true]);

    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'w_organization_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => null],
        'organizations' => [],
    ]]);

    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'person',
    ]);

    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 'person-123']);
});

test('selecting an organization is rejected when company pages are disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => true]);
    config(['trypost.platforms.linkedin-page.enabled' => false]);

    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'profile', 'email', 'w_member_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [],
    ]]);

    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'organization',
        'organization_id' => 123456,
    ]);

    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 123456]);
});

test('select-identity returns the popup callback when the session expired', function () {
    // Rendered inside the OAuth popup, so a redirect would strand it — it must
    // answer with the self-closing callback view instead.
    $response = $this->actingAs($this->user)->get(route('app.social.linkedin.select-identity'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
});

test('selecting the person creates a linkedin account', function () {
    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'profile', 'email', 'w_member_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [],
    ]]);

    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'person',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (Assert $page) => $page->where('success', true));

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn->value,
        'platform_user_id' => 'person-123',
        'username' => 'johndoe',
        'display_name' => 'John Doe',
        'status' => Status::Connected->value,
    ]);

    expect(session('linkedin_pending'))->toBeNull();
});

test('selecting the person downloads and stores the avatar', function () {
    Storage::fake();

    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'profile', 'email', 'w_member_social'],
        'person' => ['id' => 'person-avatar', 'name' => 'John Doe', 'avatar' => 'https://93.184.216.34/avatar.jpg', 'vanity_name' => 'johndoe'],
        'organizations' => [],
    ]]);

    // A public IP literal as the host lets SafeHttpFetcher's SSRF guard pass
    // without a real DNS lookup; Http::fake() intercepts before any network I/O.
    Http::fake([
        'https://93.184.216.34/avatar.jpg' => Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->actingAs($this->user)->post(route('app.social.linkedin.select'), ['type' => 'person']);

    // The avatar download (uploadFromUrl) ran and a stored path was persisted.
    Http::assertSent(fn ($request) => $request->url() === 'https://93.184.216.34/avatar.jpg');

    $account = SocialAccount::where('platform_user_id', 'person-avatar')->first();
    expect($account->getRawOriginal('avatar_url'))->not->toBeNull();
});

test('selecting an organization creates a linkedin-page account with the admin recorded in meta', function () {
    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'profile', 'email', 'w_organization_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [
            ['id' => 123456, 'name' => 'Test Company', 'vanity_name' => 'testcompany', 'logo' => null],
        ],
    ]]);

    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'organization',
        'organization_id' => 123456,
    ]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('success', true));

    $account = SocialAccount::where('platform', Platform::LinkedInPage->value)
        ->where('platform_user_id', 123456)
        ->first();

    expect($account)->not->toBeNull();
    expect($account->display_name)->toBe('Test Company');
    expect($account->username)->toBe('testcompany');
    expect($account->meta['admin_user_id'])->toBe('person-123');
    expect($account->meta['admin_name'])->toBe('John Doe');
});

test('selecting an organization the member does not administer is rejected', function () {
    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'w_organization_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [
            ['id' => 111, 'name' => 'My Company', 'vanity_name' => 'myco', 'logo' => null],
        ],
    ]]);

    // 999 is not in the admin-verified list — a tampered POST must not connect it.
    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'organization',
        'organization_id' => 999,
    ]);

    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 999]);
});

test('selecting an organization splits comma-separated approvedScopes before saving', function () {
    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        // Socialite returns LinkedIn scopes CSV-joined into a single element.
        'approved_scopes' => ['email,openid,profile,w_organization_social,r_organization_social,rw_organization_admin,w_member_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [
            ['id' => 999888, 'name' => 'Scope Company', 'vanity_name' => 'scopeco', 'logo' => null],
        ],
    ]]);

    $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'organization',
        'organization_id' => 999888,
    ]);

    $account = SocialAccount::where('platform_user_id', 999888)->first();
    expect($account->scopes)->toEqualCanonicalizing([
        'email', 'openid', 'profile',
        'w_organization_social', 'r_organization_social',
        'rw_organization_admin', 'w_member_social',
    ]);
});

test('select rejects an invalid identity type without stranding the popup', function () {
    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => [],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [],
    ]]);

    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), ['type' => 'bogus']);

    // A redirect-back would strand the popup; it must answer with the self-closing callback view.
    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('accounts/PopupCallback'));
    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
    $this->assertDatabaseMissing('social_accounts', ['platform_user_id' => 'person-123']);
});

test('select fails with expired session', function () {
    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'person',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
    $response->assertInertia(fn (Assert $page) => $page->where('message', 'Session expired. Please try again.'));
});

test('selecting the person shows network_taken when a linkedin page already occupies the network', function () {
    config()->set('trypost.self_hosted', false);

    SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'existing-linkedin-page',
    ]);

    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'test-access-token',
        'refresh_token' => 'test-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'profile', 'email', 'w_member_social'],
        'person' => ['id' => 'new-person', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [],
    ]]);

    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'person',
    ]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('success', false));
    $response->assertInertia(fn (Assert $page) => $page->where('message', __('accounts.popup_callback.network_taken')));

    $this->assertDatabaseMissing('social_accounts', [
        'platform' => Platform::LinkedIn->value,
        'platform_user_id' => 'new-person',
    ]);
});

test('user can connect multiple linkedin organizations in self-hosted mode', function () {
    config()->set('trypost.self_hosted', true);

    SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456',
    ]);

    session(['linkedin_pending' => [
        'workspace_id' => $this->workspace->id,
        'token' => 'new-access-token',
        'refresh_token' => 'new-refresh-token',
        'expires_in' => 5184000,
        'approved_scopes' => ['openid', 'profile', 'email', 'w_organization_social'],
        'person' => ['id' => 'person-123', 'name' => 'John Doe', 'avatar' => null, 'vanity_name' => 'johndoe'],
        'organizations' => [
            ['id' => 789012, 'name' => 'Another Company', 'vanity_name' => 'anothercompany', 'logo' => null],
        ],
    ]]);

    $response = $this->actingAs($this->user)->post(route('app.social.linkedin.select'), [
        'type' => 'organization',
        'organization_id' => 789012,
    ]);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('success', true));

    expect($this->workspace->socialAccounts()->where('platform', Platform::LinkedInPage)->count())->toBe(2);
});

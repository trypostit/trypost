<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Http\Controllers\Auth\OidcController;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Socialite\OidcProvider;
use App\Support\Auth\LoginMethods;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery\MockInterface;

beforeEach(function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.oidc_auth_enabled', true);
    config()->set('trypost.oidc_allowed_groups', '');
    config()->set('trypost.oidc_auto_join_enabled', false);
});

/**
 * Stands in for the real provider so the tests never leave the process.
 *
 * @param  array<string, mixed>  $claims  What the provider reports about the user.
 */
function fakeOidcDriver(array $claims = [], ?string $idToken = 'id-token-value'): MockInterface
{
    $claims = array_merge([
        'sub' => 'provider-subject-1',
        'email' => 'member@example.com',
        'name' => 'Example Member',
    ], $claims);

    $socialiteUser = (new SocialiteUser)->setRaw($claims)->map([
        'id' => $claims['sub'],
        'name' => $claims['name'] ?? null,
        'email' => $claims['email'] ?? null,
        'nickname' => $claims['preferred_username'] ?? null,
    ]);

    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('user')->andReturn($socialiteUser);
    $driver->shouldReceive('idToken')->andReturn($idToken);
    $driver->shouldReceive('endSessionEndpoint')->andReturn(null)->byDefault();

    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    return $driver;
}

// ---------------------------------------------------------------- wiring ---

test('login page loads whether oidc is enabled or not', function (bool $enabled) {
    config(['trypost.oidc_auth_enabled' => $enabled]);

    $this->get(route('login'))->assertOk();
})->with([true, false]);

test('login page shares the oidc flag and the configured display name', function () {
    config([
        'trypost.oidc_auth_enabled' => true,
        'trypost.oidc_display_name' => 'Acme SSO',
    ]);

    $response = $this->get(route('login'));

    $props = $response->original->getData()['page']['props'];

    expect($props['oidcAuthEnabled'])->toBeTrue()
        ->and($props['oidcDisplayName'])->toBe('Acme SSO');
});

test('login page reports oidc as disabled when it is off', function () {
    config(['trypost.oidc_auth_enabled' => false]);

    $props = $this->get(route('login'))->original->getData()['page']['props'];

    expect($props['oidcAuthEnabled'])->toBeFalse();
});

test('the redirect route sends the browser to the provider', function () {
    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://idp.example.com/authorize'));
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    $this->get(route('auth.oidc.redirect'))
        ->assertRedirect('https://idp.example.com/authorize');
});

test('both oidc routes 404 while the feature is disabled', function (string $route) {
    config(['trypost.oidc_auth_enabled' => false]);

    $this->get(route($route))->assertNotFound();
})->with(['auth.oidc.redirect', 'auth.oidc.callback']);

test('a failing provider sends the user back to the login page', function () {
    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('user')->andThrow(new RuntimeException('token rejected'));
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    $this->get(route('auth.oidc.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

// ----------------------------------------------------------- signing in ---

test('a returning user is matched by the provider subject', function () {
    $user = User::factory()->create([
        'oidc_id' => 'provider-subject-1',
        'email' => 'someone-else@example.com',
    ]);

    fakeOidcDriver(['sub' => 'provider-subject-1', 'email' => 'member@example.com']);

    $this->get(route('auth.oidc.callback'))->assertRedirect(route('app.home'));

    $this->assertAuthenticatedAs($user);
});

test('an existing local account is linked by email and keeps the subject', function () {
    $user = User::factory()->create(['email' => 'member@example.com', 'oidc_id' => null]);

    fakeOidcDriver();

    $this->get(route('auth.oidc.callback'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->oidc_id)->toBe('provider-subject-1');
});

test('the id token is kept for logout', function () {
    User::factory()->create(['email' => 'member@example.com']);

    fakeOidcDriver();

    $this->get(route('auth.oidc.callback'));

    expect(session(OidcController::ID_TOKEN_SESSION_KEY))->toBe('id-token-value');
});

test('signing in gets a fresh session id', function () {
    User::factory()->create(['email' => 'member@example.com']);

    fakeOidcDriver();

    $this->startSession();
    $before = session()->getId();

    $this->get(route('auth.oidc.callback'));

    expect(session()->getId())->not->toBe($before);
});

// -------------------------------------------------------------- security ---

test('an unverified email cannot claim an existing account', function () {
    $user = User::factory()->create(['email' => 'member@example.com', 'oidc_id' => null]);

    fakeOidcDriver(['email_verified' => false]);

    $this->get(route('auth.oidc.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($user->fresh()->oidc_id)->toBeNull();
});

test('a verified email is accepted', function () {
    $user = User::factory()->create(['email' => 'member@example.com']);

    fakeOidcDriver(['email_verified' => true]);

    $this->get(route('auth.oidc.callback'));

    $this->assertAuthenticatedAs($user);
});

test('an unverified email still signs in when it collides with nothing', function () {
    // Providers that simply do not do email verification report false for
    // everyone. That must not lock them out - it only rules out taking over
    // an account that already exists.
    config([
        'trypost.self_hosted' => false,
        'trypost.oidc_auto_join_enabled' => false,
    ]);

    fakeOidcDriver(['email' => 'newcomer@example.com', 'email_verified' => false]);

    $this->get(route('auth.oidc.callback'));

    $this->assertAuthenticated();
    expect(User::where('email', 'newcomer@example.com')->exists())->toBeTrue();
});

test('the subject matches even when the email is unverified', function () {
    $user = User::factory()->create([
        'oidc_id' => 'provider-subject-1',
        'email' => 'member@example.com',
    ]);

    fakeOidcDriver(['email_verified' => false]);

    $this->get(route('auth.oidc.callback'));

    $this->assertAuthenticatedAs($user);
});

test('a provider that returns no email is refused', function () {
    fakeOidcDriver(['email' => null]);

    $this->get(route('auth.oidc.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('users outside the allowed groups are turned away', function () {
    User::factory()->create(['email' => 'member@example.com']);
    config(['trypost.oidc_allowed_groups' => 'marketing, board']);

    fakeOidcDriver(['groups' => ['support']]);

    $this->get(route('auth.oidc.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user in one of the allowed groups gets in', function () {
    $user = User::factory()->create(['email' => 'member@example.com']);
    config(['trypost.oidc_allowed_groups' => 'marketing, board']);

    fakeOidcDriver(['groups' => ['support', 'board']]);

    $this->get(route('auth.oidc.callback'));

    $this->assertAuthenticatedAs($user);
});

test('without an allow list the provider alone decides', function () {
    $user = User::factory()->create(['email' => 'member@example.com']);
    config(['trypost.oidc_allowed_groups' => '']);

    fakeOidcDriver(['groups' => []]);

    $this->get(route('auth.oidc.callback'));

    $this->assertAuthenticatedAs($user);
});

// ------------------------------------------------------------ onboarding ---

test('self-hosted still requires an invite while auto-join is off', function () {
    config([
        'trypost.self_hosted' => true,
        'trypost.oidc_auto_join_enabled' => false,
    ]);

    fakeOidcDriver();

    $this->get(route('auth.oidc.callback'))->assertNotFound();

    $this->assertGuest();
});

test('auto-join places a new user on the shared account', function () {
    config([
        'trypost.self_hosted' => true,
        'trypost.oidc_auto_join_enabled' => true,
        'trypost.oidc_auto_join_role' => Role::Member->value,
    ]);

    $account = Account::factory()->create(['created_at' => now()->subDay()]);
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);

    fakeOidcDriver(['email' => 'newcomer@example.com', 'sub' => 'subject-new']);

    $this->get(route('auth.oidc.callback'))->assertRedirect(route('app.home'));

    $user = User::where('email', 'newcomer@example.com')->firstOrFail();

    expect($user->account_id)->toBe($account->id)
        ->and($user->current_workspace_id)->toBe($workspace->id)
        ->and($workspace->members()->where('users.id', $user->id)->exists())->toBeTrue();
});

test('auto-join stays off unless the instance is self-hosted', function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.oidc_auto_join_enabled' => true,
    ]);

    $account = Account::factory()->create(['created_at' => now()->subDay()]);

    fakeOidcDriver(['email' => 'newcomer@example.com', 'sub' => 'subject-new']);

    $this->get(route('auth.oidc.callback'));

    $user = User::where('email', 'newcomer@example.com')->firstOrFail();

    expect($user->account_id)->not->toBe($account->id);
});

// ---------------------------------------------------------------- logout ---

test('logging out also ends the session at the provider', function () {
    $user = User::factory()->create(['oidc_id' => 'provider-subject-1']);

    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('endSessionEndpoint')->andReturn('https://idp.example.com/logout');
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    $response = $this->actingAs($user)
        ->withSession([OidcController::ID_TOKEN_SESSION_KEY => 'id-token-value'])
        ->post(route('logout'));

    $response->assertRedirectContains('https://idp.example.com/logout');
    $response->assertRedirectContains('id_token_hint=id-token-value');

    $this->assertGuest();
});

test('no post-logout redirect is sent unless one is configured', function () {
    // Providers reject the whole logout when the URI is not registered with
    // them character for character, which would leave the user signed in while
    // believing they are not.
    config(['trypost.oidc_post_logout_redirect_uri' => null]);

    $user = User::factory()->create(['oidc_id' => 'provider-subject-1']);

    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('endSessionEndpoint')->andReturn('https://idp.example.com/logout');
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    $response = $this->actingAs($user)
        ->withSession([OidcController::ID_TOKEN_SESSION_KEY => 'id-token-value'])
        ->post(route('logout'));

    expect($response->headers->get('Location'))->not->toContain('post_logout_redirect_uri');
});

test('a configured post-logout redirect is passed through untouched', function () {
    config(['trypost.oidc_post_logout_redirect_uri' => 'https://app.example.com/']);

    $user = User::factory()->create(['oidc_id' => 'provider-subject-1']);

    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('endSessionEndpoint')->andReturn('https://idp.example.com/logout');
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    $response = $this->actingAs($user)
        ->withSession([OidcController::ID_TOKEN_SESSION_KEY => 'id-token-value'])
        ->post(route('logout'));

    expect($response->headers->get('Location'))
        ->toContain('post_logout_redirect_uri='.urlencode('https://app.example.com/'));
});

test('logout stays local when the user did not use oidc', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');

    $this->assertGuest();
});

test('logout stays local when provider logout is switched off', function () {
    config(['trypost.oidc_logout_enabled' => false]);

    $user = User::factory()->create(['oidc_id' => 'provider-subject-1']);

    $this->actingAs($user)
        ->withSession([OidcController::ID_TOKEN_SESSION_KEY => 'id-token-value'])
        ->post(route('logout'))
        ->assertRedirect('/');
});

// -------------------------------------------------------------- settings ---

test('the settings page can start an oidc connect', function () {
    $user = User::factory()->create();

    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://idp.example.com/authorize'));
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    $this->actingAs($user)
        ->get(route('app.authentication.connect-provider', 'oidc'))
        ->assertRedirect('https://idp.example.com/authorize');
});

test('the settings page lists oidc among the providers', function () {
    $user = User::factory()->create();

    $props = $this->actingAs($user)
        ->get(route('app.authentication.edit'))
        ->original->getData()['page']['props'];

    expect(collect($props['connectedAccounts'])->pluck('provider'))->toContain('oidc');
});

// ------------------------------------------------------------ key caching ---

/**
 * Builds a throwaway JWKS plus a Guzzle client that serves it, so the key
 * handling can be exercised without touching the network.
 *
 * @return array{0: Client, 1: array<string, mixed>}
 */
function fakeJwksClient(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $details = openssl_pkey_get_details($key);

    $base64url = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    $jwks = ['keys' => [[
        'kty' => 'RSA',
        'kid' => 'test-key',
        'use' => 'sig',
        'alg' => 'RS256',
        'n' => $base64url($details['rsa']['n']),
        'e' => $base64url($details['rsa']['e']),
    ]]];

    $discovery = [
        'issuer' => 'https://idp.example.com',
        'authorization_endpoint' => 'https://idp.example.com/authorize',
        'token_endpoint' => 'https://idp.example.com/token',
        'userinfo_endpoint' => 'https://idp.example.com/userinfo',
        'jwks_uri' => 'https://idp.example.com/jwks',
    ];

    // Discovery is fetched once and then cached, so every later request in a
    // test is for the key set.
    $handler = new MockHandler([
        new Response(200, [], json_encode($discovery)),
        new Response(200, [], json_encode($jwks)),
        new Response(200, [], json_encode($jwks)),
        new Response(200, [], json_encode($jwks)),
    ]);

    return [new Client(['handler' => HandlerStack::create($handler)]), $jwks];
}

test('signing keys survive a cache store that serializes', function () {
    // The array store keeps objects as they are; a store that serializes would
    // choke on the OpenSSL key objects inside a parsed key set, so the raw
    // document has to be what gets cached.
    config(['cache.default' => 'file']);
    cache()->clear();

    config([
        'services.oidc.discovery_url' => 'https://idp.example.com',
        'services.oidc.client_id' => 'client-id',
    ]);

    [$client] = fakeJwksClient();

    $provider = new OidcProvider(request(), 'client-id', 'client-secret', 'https://app.example.com/auth/oidc/callback');
    $provider->setHttpClient($client);

    $method = new ReflectionMethod($provider, 'signingKeys');
    $method->setAccessible(true);

    $first = $method->invoke($provider);

    // Second call is served from the cache - this is where the old code blew up.
    $second = $method->invoke($provider);

    expect($first)->toBeArray()->not->toBeEmpty()
        ->and($second)->toBeArray()->not->toBeEmpty()
        ->and(array_keys($second))->toBe(array_keys($first));
});

test('a refresh refetches the key set', function () {
    config(['cache.default' => 'file']);
    cache()->clear();

    config(['services.oidc.discovery_url' => 'https://idp.example.com']);

    [$client] = fakeJwksClient();

    $provider = new OidcProvider(request(), 'client-id', 'client-secret', 'https://app.example.com/auth/oidc/callback');
    $provider->setHttpClient($client);

    $method = new ReflectionMethod($provider, 'signingKeys');
    $method->setAccessible(true);

    $method->invoke($provider);

    // Forces a second trip to the provider, which the mock handler answers.
    expect($method->invoke($provider, true))->toBeArray()->not->toBeEmpty();
});

// ------------------------------------------------- password sign-in toggle ---

test('the password routes stay open by default', function (string $route) {
    config(['trypost.password_login_enabled' => true]);

    $this->get(route($route))->assertOk();
})->with(['login', 'password.request']);

test('switching password sign-in off closes its routes', function () {
    config([
        'trypost.password_login_enabled' => false,
        'trypost.oidc_auth_enabled' => true,
    ]);

    // The login page itself stays - it is where the provider buttons live.
    $this->get(route('login'))->assertOk();

    $this->post(route('login.store'), [
        'email' => 'member@example.com',
        'password' => 'Password123!',
    ])->assertNotFound();

    $this->get(route('password.request'))->assertNotFound();
    $this->post(route('password.email'), ['email' => 'member@example.com'])->assertNotFound();
    $this->get(route('password.reset', ['token' => 'whatever']))->assertNotFound();
});

test('a correct password is still refused once sign-in is switched off', function () {
    config([
        'trypost.password_login_enabled' => false,
        'trypost.oidc_auth_enabled' => true,
    ]);

    User::factory()->create([
        'email' => 'member@example.com',
        'password' => 'Password123!',
    ]);

    $this->post(route('login.store'), [
        'email' => 'member@example.com',
        'password' => 'Password123!',
    ])->assertNotFound();

    $this->assertGuest();
});

test('password sign-in cannot be switched off while it is the only way in', function () {
    // Otherwise one environment variable locks every user out of the instance.
    config([
        'trypost.password_login_enabled' => false,
        'trypost.oidc_auth_enabled' => false,
        'trypost.google_auth_enabled' => false,
        'trypost.github_auth_enabled' => false,
    ]);

    expect(LoginMethods::passwordEnabled())->toBeTrue();

    $this->get(route('password.request'))->assertOk();
});

test('the login page says whether the password form belongs there', function (bool $enabled) {
    config([
        'trypost.password_login_enabled' => $enabled,
        'trypost.oidc_auth_enabled' => true,
    ]);

    $props = $this->get(route('login'))->original->getData()['page']['props'];

    expect($props['passwordLoginEnabled'])->toBe($enabled);
})->with([true, false]);

test('registering with a password is closed while password sign-in is off', function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.password_login_enabled' => false,
        'trypost.oidc_auth_enabled' => true,
    ]);

    // The page stays - it carries the provider buttons.
    $this->get(route('register'))->assertOk();

    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'nobody@example.com',
        'password' => 'Password123!',
    ])->assertNotFound();

    expect(User::where('email', 'nobody@example.com')->exists())->toBeFalse();
});

test('logging out from the app hands the browser a full page visit', function () {
    // The app posts logout through Inertia. A plain redirect gets followed by
    // fetch(), which dies on the provider's CORS preflight - the browser never
    // navigates and the provider session survives. Inertia answers a 409 with
    // X-Inertia-Location so the client leaves the page properly.
    config(['trypost.oidc_post_logout_redirect_uri' => null]);

    $user = User::factory()->create(['oidc_id' => 'provider-subject-1']);

    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('endSessionEndpoint')->andReturn('https://idp.example.com/logout');
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    $response = $this->actingAs($user)
        ->withSession([OidcController::ID_TOKEN_SESSION_KEY => 'id-token-value'])
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('logout'));

    $response->assertStatus(409);

    expect($response->headers->get('X-Inertia-Location'))
        ->toContain('https://idp.example.com/logout')
        ->toContain('id_token_hint=id-token-value');

    $this->assertGuest();
});

// ------------------------------------------------- roles from provider groups ---

/**
 * A workspace with one member, so role changes have something to act on.
 *
 * @return array{0: User, 1: Workspace}
 */
function memberInWorkspace(string $email, string $role = 'member'): array
{
    $account = Account::factory()->create(['created_at' => now()->subDay()]);
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);

    $user = User::factory()->create([
        'email' => $email,
        'account_id' => $account->id,
        'oidc_id' => 'provider-subject-1',
    ]);
    $workspace->members()->attach($user->id, ['role' => $role]);

    return [$user, $workspace];
}

test('a member of an admin group becomes workspace admin on sign-in', function () {
    config(['trypost.oidc_admin_groups' => 'board, ops']);

    [$user, $workspace] = memberInWorkspace('member@example.com');

    fakeOidcDriver(['groups' => ['staff', 'ops']]);

    $this->get(route('auth.oidc.callback'));

    expect($workspace->members()->where('users.id', $user->id)->first()->pivot->role)
        ->toBe('admin');
});

test('losing the admin group drops the role again on the next sign-in', function () {
    // This is what makes offboarding work in one place: take someone out of
    // the group at the provider and their rights go with it.
    config([
        'trypost.oidc_admin_groups' => 'board',
        'trypost.oidc_auto_join_role' => 'member',
    ]);

    [$user, $workspace] = memberInWorkspace('member@example.com', 'admin');

    fakeOidcDriver(['groups' => ['staff']]);

    $this->get(route('auth.oidc.callback'));

    expect($workspace->members()->where('users.id', $user->id)->first()->pivot->role)
        ->toBe('member');
});

test('roles are left alone while no admin group is configured', function () {
    config(['trypost.oidc_admin_groups' => '']);

    [$user, $workspace] = memberInWorkspace('member@example.com', 'admin');

    fakeOidcDriver(['groups' => []]);

    $this->get(route('auth.oidc.callback'));

    // Without the setting the application stays in charge of roles.
    expect($workspace->members()->where('users.id', $user->id)->first()->pivot->role)
        ->toBe('admin');
});

test('groups arrive whether the provider sends a list or a string', function (mixed $claim) {
    // Keycloak-style arrays and ADFS-style strings both have to work.
    config(['trypost.oidc_allowed_groups' => 'board']);

    $user = User::factory()->create(['email' => 'member@example.com']);

    fakeOidcDriver(['groups' => $claim]);

    $this->get(route('auth.oidc.callback'));

    $this->assertAuthenticatedAs($user);
})->with([
    [['staff', 'board']],
    ['staff board'],
    ['staff,board'],
]);

test('the first user on an empty instance still gets a workspace', function () {
    // Auto-join suppresses the personal workspace because it expects a shared
    // account to join. On a fresh install there is none, and without this the
    // user lands in an application with nowhere to work.
    config([
        'trypost.self_hosted' => true,
        'trypost.oidc_auto_join_enabled' => true,
    ]);

    expect(Account::count())->toBe(0);

    fakeOidcDriver(['email' => 'first@example.com', 'sub' => 'subject-first']);

    $this->get(route('auth.oidc.callback'));

    $user = User::where('email', 'first@example.com')->firstOrFail();

    expect($user->workspaces)->toHaveCount(1)
        ->and($user->account->owner_id)->toBe($user->id);
});

test('the account owner is left out of the group role sync', function () {
    // Ownership outranks the workspace role, so demoting the owner would show
    // "member" in the interface while every permission stays in place.
    config(['trypost.oidc_admin_groups' => 'board']);

    $account = Account::factory()->create(['created_at' => now()->subDay()]);
    $owner = User::factory()->create([
        'account_id' => $account->id,
        'email' => 'member@example.com',
        'oidc_id' => 'provider-subject-1',
    ]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, ['role' => 'admin']);

    fakeOidcDriver(['groups' => ['staff']]);

    $this->get(route('auth.oidc.callback'));

    expect($workspace->members()->where('users.id', $owner->id)->first()->pivot->role)
        ->toBe('admin');
});

// ------------------------------------------ handing the account to the groups ---

test('ownership is released so no one sits outside the group system', function () {
    config([
        'trypost.oidc_admin_groups' => 'board',
        'trypost.oidc_release_ownership' => true,
    ]);

    $account = Account::factory()->create(['created_at' => now()->subDay()]);
    $owner = User::factory()->create([
        'account_id' => $account->id,
        'email' => 'member@example.com',
        'oidc_id' => 'provider-subject-1',
    ]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, ['role' => 'admin']);

    fakeOidcDriver(['groups' => ['staff']]);

    $this->get(route('auth.oidc.callback'));

    // Ownership gone, and with it the exemption: the role now follows the
    // groups like everyone else's.
    expect($account->fresh()->owner_id)->toBeNull()
        ->and($workspace->members()->where('users.id', $owner->id)->first()->pivot->role)
        ->toBe('member');
});

test('ownership is left alone unless releasing it was asked for', function () {
    config([
        'trypost.oidc_admin_groups' => 'board',
        'trypost.oidc_release_ownership' => false,
    ]);

    $account = Account::factory()->create(['created_at' => now()->subDay()]);
    $owner = User::factory()->create([
        'account_id' => $account->id,
        'email' => 'member@example.com',
        'oidc_id' => 'provider-subject-1',
    ]);
    $account->update(['owner_id' => $owner->id]);
    Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $owner->id]);

    fakeOidcDriver(['groups' => ['staff']]);

    $this->get(route('auth.oidc.callback'));

    expect($account->fresh()->owner_id)->toBe($owner->id);
});

// ------------------------------------------------- ID token attack surface ---

/**
 * A provider wired to a throwaway key pair, plus the pieces needed to forge
 * tokens against it.
 *
 * @return array{provider: OidcProvider, private: string, public: string, kid: string}
 */
function oidcProviderUnderTest(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);
    $publicPem = $details['key'];

    $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $kid = 'test-key';

    $jwks = ['keys' => [[
        'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
        'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
    ]]];

    $discovery = [
        'issuer' => 'https://idp.example.com',
        'authorization_endpoint' => 'https://idp.example.com/authorize',
        'token_endpoint' => 'https://idp.example.com/token',
        'userinfo_endpoint' => 'https://idp.example.com/userinfo',
        'jwks_uri' => 'https://idp.example.com/jwks',
    ];

    // Enough responses for repeated discovery/JWKS fetches, including a
    // refresh attempt after a rejected signature.
    $queue = [];
    for ($i = 0; $i < 8; $i++) {
        $queue[] = new Response(200, [], json_encode($discovery));
        $queue[] = new Response(200, [], json_encode($jwks));
    }

    config([
        'services.oidc.discovery_url' => 'https://idp.example.com',
        'cache.default' => 'array',
    ]);
    cache()->clear();

    // The provider reads the nonce from the request's session. Without binding
    // it here, validation fails before it ever looks at a signature - and every
    // attack test would pass for the wrong reason.
    $request = request();
    $request->setLaravelSession(app('session.store'));

    $provider = new OidcProvider($request, 'client-id', 'client-secret', 'https://app.example.com/auth/oidc/callback');
    $provider->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler($queue))]));

    return ['provider' => $provider, 'private' => $privatePem, 'public' => $publicPem, 'kid' => $kid];
}

/**
 * Runs the provider's ID token validation and says whether it accepted.
 */
function idTokenAccepted(OidcProvider $provider, string $token): bool
{
    $method = new ReflectionMethod($provider, 'validateIdToken');
    $method->setAccessible(true);

    try {
        $method->invoke($provider, $token);

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function idTokenClaims(array $overrides = []): array
{
    return array_merge([
        'iss' => 'https://idp.example.com',
        'aud' => ['client-id'],
        'sub' => 'provider-subject-1',
        'exp' => time() + 300,
        'iat' => time(),
        'nonce' => 'the-expected-nonce',
    ], $overrides);
}

beforeEach(function () {
    $this->startSession();
});

test('a properly signed token is accepted (control)', function () {
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $token = JWT::encode(idTokenClaims(), $ctx['private'], 'RS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeTrue();
});

test('an unsigned token is rejected', function () {
    // The alg=none attack: strip the signature and claim the token needs none.
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $b64 = fn (array $d): string => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
    $token = $b64(['alg' => 'none', 'typ' => 'JWT', 'kid' => $ctx['kid']]).'.'.$b64(idTokenClaims()).'.';

    expect(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

test('a token signed with the public key as an HMAC secret is rejected', function () {
    // Algorithm confusion: hand back HS256 and use the provider's own public
    // key as the shared secret. Fatal wherever the algorithm is taken from the
    // token instead of the key.
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $token = JWT::encode(idTokenClaims(), $ctx['public'], 'HS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

test('a token signed by a different key is rejected', function () {
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $attacker = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($attacker, $attackerPem);

    $token = JWT::encode(idTokenClaims(), $attackerPem, 'RS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

test('a token for a different audience is rejected', function () {
    // Token substitution: a token the provider issued for another client.
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $token = JWT::encode(idTokenClaims(['aud' => ['some-other-client']]), $ctx['private'], 'RS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

test('a token from a different issuer is rejected', function () {
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $token = JWT::encode(idTokenClaims(['iss' => 'https://evil.example.com']), $ctx['private'], 'RS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

test('an expired token is rejected', function () {
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $token = JWT::encode(idTokenClaims(['exp' => time() - 3600]), $ctx['private'], 'RS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

test('a token replayed from another login is rejected', function () {
    // Right signature, right audience, wrong login: the nonce ties a token to
    // the one authorization request that asked for it.
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $token = JWT::encode(idTokenClaims(['nonce' => 'a-nonce-from-another-login']), $ctx['private'], 'RS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

test('a token without a nonce is rejected', function () {
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $claims = idTokenClaims();
    unset($claims['nonce']);
    $token = JWT::encode($claims, $ctx['private'], 'RS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

test('the nonce is consumed, so the same token cannot be used twice', function () {
    $ctx = oidcProviderUnderTest();
    session(['oidc.nonce' => 'the-expected-nonce']);

    $token = JWT::encode(idTokenClaims(), $ctx['private'], 'RS256', $ctx['kid']);

    expect(idTokenAccepted($ctx['provider'], $token))->toBeTrue()
        ->and(idTokenAccepted($ctx['provider'], $token))->toBeFalse();
});

// --------------------------------------------- the authorization round-trip ---

/**
 * Drives the real provider (no mocked driver), so state and PKCE are actually
 * exercised instead of being skipped.
 *
 * @return array{provider: OidcProvider, private: string, kid: string}
 */
function oidcProviderForRoundTrip(Request $request): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);
    $b64 = fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    $kid = 'test-key';

    $discovery = [
        'issuer' => 'https://idp.example.com',
        'authorization_endpoint' => 'https://idp.example.com/authorize',
        'token_endpoint' => 'https://idp.example.com/token',
        'userinfo_endpoint' => 'https://idp.example.com/userinfo',
        'jwks_uri' => 'https://idp.example.com/jwks',
    ];
    $jwks = ['keys' => [[
        'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
        'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
    ]]];

    $queue = [];
    for ($i = 0; $i < 6; $i++) {
        $queue[] = new Response(200, [], json_encode($discovery));
        $queue[] = new Response(200, [], json_encode($jwks));
    }

    config(['services.oidc.discovery_url' => 'https://idp.example.com', 'cache.default' => 'array']);
    cache()->clear();

    $provider = new OidcProvider($request, 'client-id', 'client-secret', 'https://app.example.com/auth/oidc/callback');
    $provider->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler($queue))]));

    return ['provider' => $provider, 'private' => $privatePem, 'kid' => $kid];
}

test('a callback whose state does not match the session is refused', function () {
    // Without this, an attacker can start a login with their own account and
    // hand the victim the callback URL, landing the victim in the attacker's
    // session. The mocked driver used elsewhere skips this check entirely.
    $this->startSession();
    session(['state' => 'the-state-we-issued']);

    $request = Request::create('/auth/oidc/callback', 'GET', [
        'code' => 'whatever',
        'state' => 'a-state-from-somewhere-else',
    ]);
    $request->setLaravelSession(app('session.store'));

    $ctx = oidcProviderForRoundTrip($request);

    expect(fn () => $ctx['provider']->user())
        ->toThrow(InvalidStateException::class);
});

test('a callback with no state at all is refused', function () {
    $this->startSession();
    session(['state' => 'the-state-we-issued']);

    $request = Request::create('/auth/oidc/callback', 'GET', ['code' => 'whatever']);
    $request->setLaravelSession(app('session.store'));

    $ctx = oidcProviderForRoundTrip($request);

    expect(fn () => $ctx['provider']->user())
        ->toThrow(InvalidStateException::class);
});

test('the authorization request carries PKCE, a nonce and a state', function () {
    $this->startSession();

    $request = Request::create('/auth/oidc/redirect', 'GET');
    $request->setLaravelSession(app('session.store'));

    $ctx = oidcProviderForRoundTrip($request);

    $target = $ctx['provider']->redirect()->getTargetUrl();
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

    expect($query['code_challenge_method'] ?? null)->toBe('S256')
        ->and($query['code_challenge'] ?? null)->not->toBeEmpty()
        ->and($query['state'] ?? null)->not->toBeEmpty()
        ->and($query['nonce'] ?? null)->not->toBeEmpty()
        ->and($query['scope'] ?? '')->toContain('openid');

    // Both have to be held server-side, or neither proves anything.
    expect(session('state'))->toBe($query['state'])
        ->and(session('oidc.nonce'))->toBe($query['nonce'])
        ->and(session('code_verifier'))->not->toBeEmpty();

    // The challenge must actually derive from the stored verifier.
    $expected = rtrim(strtr(base64_encode(hash('sha256', (string) session('code_verifier'), true)), '+/', '-_'), '=');
    expect($query['code_challenge'])->toBe($expected);
});

test('a failed sign-in never writes credentials to the log', function () {
    // The callback logs why it failed, which is the only way to debug a
    // misconfigured provider. It must not turn the log into a place where
    // client secrets or tokens end up.
    config([
        'trypost.oidc_auth_enabled' => true,
        'services.oidc.client_secret' => 'super-secret-client-value',
    ]);

    $captured = [];
    Log::listen(function ($message) use (&$captured) {
        $captured[] = $message->message.' '.json_encode($message->context);
    });

    $driver = Mockery::mock(OidcProvider::class);
    $driver->shouldReceive('user')->andThrow(new RuntimeException(
        'token endpoint said: {"error":"invalid_client"}'
    ));
    Socialite::shouldReceive('driver')->with('oidc')->andReturn($driver);

    $this->get(route('auth.oidc.callback'))->assertRedirect(route('login'));

    $log = implode("\n", $captured);

    expect($log)->not->toBeEmpty()
        ->and($log)->not->toContain('super-secret-client-value')
        ->and($log)->not->toContain('client_secret')
        ->and($log)->not->toContain('id_token')
        ->and($log)->not->toContain('access_token');
});

test('the oidc endpoints are throttled', function (string $route) {
    // Both are public and make the server call out to the provider, so they
    // must not be free to hammer.
    $middleware = collect(Route::getRoutes())
        ->first(fn ($r) => $r->getName() === $route)
        ?->gatherMiddleware() ?? [];

    expect($middleware)->toContain('throttle:30,1');
})->with(['auth.oidc.redirect', 'auth.oidc.callback']);

test('every string the login page can show is translated', function (string $locale) {
    // A missing key renders as the raw key name in the interface, which is how
    // "Enter your email and password below" stayed on a page that has no
    // password field.
    app()->setLocale($locale);

    foreach ([
        'auth.login.description',
        'auth.login.description_without_password',
        'auth.oidc_login',
        'auth.oidc_signup',
        'auth.oidc_failed',
        'auth.oidc_group_denied',
        'auth.oidc_email_missing',
        'auth.oidc_email_unverified',
    ] as $key) {
        expect(__($key))->not->toBe($key, "missing translation: {$key} ({$locale})");
    }
})->with(['en', 'de']);

// ------------------------------------------------- discovery & userinfo ---

/**
 * A provider whose HTTP answers the test dictates, so the paths around a
 * misconfigured or thin identity provider can be exercised directly.
 *
 * @param  array<int, Response>  $responses
 */
function oidcProviderAnswering(array $responses, string $discoveryUrl = 'https://idp.example.com'): array
{
    config([
        'services.oidc.discovery_url' => $discoveryUrl,
        'cache.default' => 'array',
    ]);
    cache()->clear();

    $history = [];
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    $request = request();
    $request->setLaravelSession(app('session.store'));

    $provider = new OidcProvider($request, 'client-id', 'client-secret', 'https://app.example.com/auth/oidc/callback');
    $provider->setHttpClient(new Client(['handler' => $stack]));

    return ['provider' => $provider, 'history' => &$history];
}

/** Calls a protected method on the provider. */
function callOnProvider(OidcProvider $provider, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($provider, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($provider, ...$arguments);
}

function fullDiscovery(array $overrides = []): array
{
    return array_merge([
        'issuer' => 'https://idp.example.com',
        'authorization_endpoint' => 'https://idp.example.com/authorize',
        'token_endpoint' => 'https://idp.example.com/token',
        'userinfo_endpoint' => 'https://idp.example.com/userinfo',
        'jwks_uri' => 'https://idp.example.com/jwks',
    ], $overrides);
}

test('a discovery document without the endpoints we need is refused', function (array $document) {
    // Half a document is worse than none: the login would fail later, on a
    // page that cannot say why.
    $ctx = oidcProviderAnswering([new Response(200, [], json_encode($document))]);

    expect(fn () => $ctx['provider']->discovery())->toThrow(RuntimeException::class);
})->with([
    'nothing at all' => [[]],
    'no authorization endpoint' => [['token_endpoint' => 'https://idp.example.com/token']],
    'no token endpoint' => [['authorization_endpoint' => 'https://idp.example.com/authorize']],
]);

test('a discovery response that is not a document at all is refused', function () {
    $ctx = oidcProviderAnswering([new Response(200, [], '<html>login here</html>')]);

    expect(fn () => $ctx['provider']->discovery())->toThrow(RuntimeException::class);
});

test('the discovery document is fetched once and then reused', function () {
    // Every sign-in would otherwise pay for the round-trip, and a provider
    // under load would feel it.
    // Three answers are queued on purpose: without the cache the extra calls
    // would be served rather than blowing up, so the count is what fails.
    $ctx = oidcProviderAnswering(array_fill(0, 3, new Response(200, [], json_encode(fullDiscovery()))));

    $ctx['provider']->discovery();
    $ctx['provider']->discovery();
    $ctx['provider']->discovery();

    expect($ctx['history'])->toHaveCount(1);
});

test('a bare issuer URL is expanded to the well-known path', function () {
    // Pasting the issuer is the easier thing to get right, so it has to work.
    $ctx = oidcProviderAnswering(
        [new Response(200, [], json_encode(fullDiscovery()))],
        'https://idp.example.com/realms/club',
    );

    $ctx['provider']->discovery();

    expect((string) $ctx['history'][0]['request']->getUri())
        ->toBe('https://idp.example.com/realms/club/.well-known/openid-configuration');
});

test('a discovery URL that already points at the document is left alone', function () {
    $ctx = oidcProviderAnswering(
        [new Response(200, [], json_encode(fullDiscovery()))],
        'https://idp.example.com/.well-known/openid-configuration',
    );

    $ctx['provider']->discovery();

    expect((string) $ctx['history'][0]['request']->getUri())
        ->toBe('https://idp.example.com/.well-known/openid-configuration');
});

test('an unconfigured discovery URL fails instead of guessing', function () {
    $ctx = oidcProviderAnswering([]);
    config(['services.oidc.discovery_url' => '']);

    expect(fn () => $ctx['provider']->discovery())->toThrow(RuntimeException::class);
});

test('a provider that publishes no logout endpoint reports none', function () {
    // Not every provider offers RP-initiated logout; that is not an error.
    $ctx = oidcProviderAnswering([
        new Response(200, [], json_encode(fullDiscovery(['end_session_endpoint' => null]))),
    ]);

    expect($ctx['provider']->endSessionEndpoint())->toBeNull();
});

test('an unreachable provider does not stop anyone logging out', function () {
    // Logout has to work even when the provider is down, or a user is stuck
    // signed in with no way to end the session.
    $ctx = oidcProviderAnswering([new Response(500, [], 'gateway down')]);

    expect($ctx['provider']->endSessionEndpoint())->toBeNull();
});

test('a provider without a userinfo endpoint is refused', function () {
    $ctx = oidcProviderAnswering([
        new Response(200, [], json_encode(fullDiscovery(['userinfo_endpoint' => null]))),
    ]);

    expect(fn () => callOnProvider($ctx['provider'], 'getUserByToken', 'access-token'))
        ->toThrow(RuntimeException::class);
});

test('claims the userinfo endpoint leaves out are filled in from the ID token', function () {
    // Providers commonly put `groups` in the ID token only. Losing it here
    // would silently strip everyone of their group-derived role.
    $ctx = oidcProviderAnswering([
        new Response(200, [], json_encode(fullDiscovery())),
        new Response(200, [], json_encode(['sub' => 'provider-subject-1', 'email' => 'member@example.com'])),
    ]);

    $reflection = new ReflectionProperty($ctx['provider'], 'idTokenClaims');
    $reflection->setAccessible(true);
    $reflection->setValue($ctx['provider'], ['groups' => ['board'], 'sub' => 'provider-subject-1']);

    $claims = callOnProvider($ctx['provider'], 'getUserByToken', 'access-token');

    expect($claims['groups'])->toBe(['board'])
        ->and($claims['email'])->toBe('member@example.com');
});

test('userinfo wins over the ID token where both carry a claim', function () {
    // The precedence is worth pinning down: userinfo is the fresher answer.
    $ctx = oidcProviderAnswering([
        new Response(200, [], json_encode(fullDiscovery())),
        new Response(200, [], json_encode(['sub' => 'provider-subject-1', 'email' => 'new@example.com'])),
    ]);

    $reflection = new ReflectionProperty($ctx['provider'], 'idTokenClaims');
    $reflection->setAccessible(true);
    $reflection->setValue($ctx['provider'], ['email' => 'stale@example.com', 'sub' => 'provider-subject-1']);

    expect(callOnProvider($ctx['provider'], 'getUserByToken', 'access-token')['email'])
        ->toBe('new@example.com');
});

test('a provider that returns no subject is refused', function () {
    // Without a subject there is nothing stable to tie the account to.
    $ctx = oidcProviderAnswering([]);

    expect(fn () => callOnProvider($ctx['provider'], 'mapUserToObject', ['email' => 'member@example.com']))
        ->toThrow(RuntimeException::class);
});

test('the display name falls back through what the provider did send', function (array $claims, string $expected) {
    $ctx = oidcProviderAnswering([]);

    $user = callOnProvider($ctx['provider'], 'mapUserToObject', $claims + ['sub' => 'provider-subject-1']);

    expect($user->getName())->toBe($expected);
})->with([
    'a full name' => [['name' => 'Example Member'], 'Example Member'],
    'given and family name' => [['given_name' => 'Example', 'family_name' => 'Member'], 'Example Member'],
    'only a username' => [['preferred_username' => 'member'], 'member'],
]);

test('a response with no ID token at all is refused', function () {
    $ctx = oidcProviderUnderTest();

    expect(idTokenAccepted($ctx['provider'], ''))->toBeFalse();
});

// ----------------------------------------- linking a provider to oneself ---

test('connecting stores the subject on the signed-in account', function () {
    $user = User::factory()->create(['oidc_id' => null]);

    fakeOidcDriver(['sub' => 'provider-subject-9']);

    $this->actingAs($user)->get(route('auth.oidc.callback'));

    expect($user->fresh()->oidc_id)->toBe('provider-subject-9');
});

test('a subject already linked to someone else cannot be taken over', function () {
    // Otherwise anyone who can sign in at the provider could attach that
    // identity to a second local account and reach it from both.
    $owner = User::factory()->create(['oidc_id' => 'provider-subject-9']);
    $other = User::factory()->create(['oidc_id' => null]);

    fakeOidcDriver(['sub' => 'provider-subject-9']);

    $this->actingAs($other)
        ->get(route('auth.oidc.callback'))
        ->assertSessionHas('flash.error');

    expect($other->fresh()->oidc_id)->toBeNull()
        ->and($owner->fresh()->oidc_id)->toBe('provider-subject-9');
});

test('connecting the same subject again changes nothing', function () {
    $user = User::factory()->create(['oidc_id' => 'provider-subject-9']);

    fakeOidcDriver(['sub' => 'provider-subject-9']);

    $this->actingAs($user)
        ->get(route('auth.oidc.callback'))
        ->assertSessionHas('flash.success');

    expect($user->fresh()->oidc_id)->toBe('provider-subject-9');
});

// ------------------------------------------------------- token exchange ---

/**
 * Key material plus the JWKS document that publishes it.
 *
 * @return array{private: string, kid: string, jwks: array<string, mixed>}
 */
function oidcTestKeys(): array
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($key, $privatePem);
    $details = openssl_pkey_get_details($key);

    $b64 = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $kid = 'test-key';

    return [
        'private' => $privatePem,
        'kid' => $kid,
        'jwks' => ['keys' => [[
            'kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]],
    ];
}

test('the token exchange verifies the ID token it was handed', function () {
    // The whole chain in one go: discovery, the code-for-token call, and the
    // signature check against the published keys.
    $keys = oidcTestKeys();
    $token = JWT::encode(idTokenClaims(), $keys['private'], 'RS256', $keys['kid']);

    $ctx = oidcProviderAnswering([
        new Response(200, [], json_encode(fullDiscovery())),
        new Response(200, [], json_encode(['access_token' => 'at', 'id_token' => $token])),
        new Response(200, [], json_encode($keys['jwks'])),
    ]);
    session(['oidc.nonce' => 'the-expected-nonce']);

    $response = $ctx['provider']->getAccessTokenResponse('the-code');

    expect($response['access_token'])->toBe('at')
        ->and($ctx['provider']->idToken())->toBe($token);
});

test('a token response with no ID token is refused', function () {
    // An OIDC provider that answers like a plain OAuth2 one proves nothing
    // about who signed in.
    $ctx = oidcProviderAnswering([
        new Response(200, [], json_encode(fullDiscovery())),
        new Response(200, [], json_encode(['access_token' => 'at'])),
    ]);
    session(['oidc.nonce' => 'the-expected-nonce']);

    expect(fn () => $ctx['provider']->getAccessTokenResponse('the-code'))
        ->toThrow(RuntimeException::class);
});

test('a token response carrying a forged ID token is refused', function () {
    // Signed with a key the provider never published.
    $published = oidcTestKeys();
    $attacker = oidcTestKeys();
    $token = JWT::encode(idTokenClaims(), $attacker['private'], 'RS256', $published['kid']);

    $ctx = oidcProviderAnswering([
        new Response(200, [], json_encode(fullDiscovery())),
        new Response(200, [], json_encode(['access_token' => 'at', 'id_token' => $token])),
        new Response(200, [], json_encode($published['jwks'])),
        new Response(200, [], json_encode($published['jwks'])),
    ]);
    session(['oidc.nonce' => 'the-expected-nonce']);

    expect(fn () => $ctx['provider']->getAccessTokenResponse('the-code'))
        ->toThrow(RuntimeException::class, 'The ID token could not be verified');
});

<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\RefreshSocialToken;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use App\Support\Social\PendingConnection;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
});

/**
 * Every Socialite client a connect callback builds talks to this stack, so a
 * callback that reaches the provider is recorded instead of leaving the test.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function recordConnectProviderCalls(string $service, int $status = 400): ArrayObject
{
    $history = new ArrayObject;
    $stack = HandlerStack::create(new MockHandler(array_fill(0, 5, new GuzzleResponse($status, ['Content-Type' => 'application/json'], '{"error":"access_denied"}'))));
    $stack->push(Middleware::history($history));

    config()->set("services.{$service}.guzzle", ['handler' => $stack]);

    return $history;
}

dataset('connect starts', [
    'linkedin' => ['app.social.linkedin.connect', 'get', ['linkedin', 'linkedin-page'], []],
    'x' => ['app.social.x.connect', 'get', ['x'], []],
    'tiktok' => ['app.social.tiktok.connect', 'get', ['tiktok'], []],
    'youtube' => ['app.social.youtube.connect', 'get', ['youtube'], []],
    'facebook' => ['app.social.facebook.connect', 'get', ['facebook'], []],
    'instagram' => ['app.social.instagram.connect', 'get', ['instagram'], []],
    'instagram via facebook' => ['app.social.instagram-facebook.connect', 'get', ['instagram-facebook'], []],
    'threads' => ['app.social.threads.connect', 'get', ['threads'], []],
    'pinterest' => ['app.social.pinterest.connect', 'get', ['pinterest'], []],
    'bluesky form' => ['app.social.bluesky.connect', 'get', ['bluesky'], []],
    'bluesky app password' => ['app.social.bluesky.store', 'post', ['bluesky'], ['identifier' => 'brand.bsky.social', 'password' => 'xxxx-xxxx-xxxx-xxxx']],
    'mastodon form' => ['app.social.mastodon.connect', 'get', ['mastodon'], []],
    'mastodon instance' => ['app.social.mastodon.authorize', 'post', ['mastodon'], ['instance' => 'https://mastodon.example']],
    'telegram' => ['app.social.telegram.connect', 'post', ['telegram'], []],
    'discord' => ['app.social.discord.connect', 'get', ['discord'], []],
    'google business' => ['app.social.google-business.connect', 'get', ['google_business'], []],
]);

dataset('connect callbacks', [
    'linkedin' => ['app.social.linkedin.callback', []],
    'x' => ['app.social.x.callback', []],
    'tiktok' => ['app.social.tiktok.callback', []],
    'youtube' => ['app.social.youtube.callback', []],
    'facebook' => ['app.social.facebook.callback', []],
    'instagram' => ['app.social.instagram.callback', []],
    'instagram via facebook' => ['app.social.instagram-facebook.callback', []],
    'threads' => ['app.social.threads.callback', ['threads_oauth_state' => 'issued-state']],
    'pinterest' => ['app.social.pinterest.callback', []],
    'discord' => ['app.social.discord.callback', []],
    'google business' => ['app.social.google-business.callback', []],
    'mastodon' => ['app.social.mastodon.callback', [
        'mastodon_instance' => 'https://mastodon.example',
        'mastodon_client_id' => 'client-id',
        'mastodon_client_secret' => 'client-secret',
        'mastodon_oauth_state' => 'issued-state',
    ]],
]);

dataset('socialite callbacks', [
    'linkedin' => ['app.social.linkedin.callback', 'linkedin-openid'],
    'x' => ['app.social.x.callback', 'x'],
    'tiktok' => ['app.social.tiktok.callback', 'tiktok'],
    'youtube' => ['app.social.youtube.callback', 'google'],
    'facebook' => ['app.social.facebook.callback', 'facebook'],
    'instagram' => ['app.social.instagram.callback', 'instagram'],
    'instagram via facebook' => ['app.social.instagram-facebook.callback', 'facebook'],
    'pinterest' => ['app.social.pinterest.callback', 'pinterest'],
    'discord' => ['app.social.discord.callback', 'discord'],
    'google business' => ['app.social.google-business.callback', 'google-business'],
]);

/**
 * The network a connect route belongs to, as the confirmation page names it.
 */
function connectRoutePlatform(string $routeName): Platform
{
    $segment = explode('.', $routeName)[2];

    return Platform::tryFrom($segment) ?? Platform::from(str_replace('-', '_', $segment));
}

dataset('confirmation pages', [
    'linkedin' => [Platform::LinkedIn],
    'x' => [Platform::X],
    'tiktok' => [Platform::TikTok],
    'youtube' => [Platform::YouTube],
    'facebook' => [Platform::Facebook],
    'instagram' => [Platform::Instagram],
    'instagram via facebook' => [Platform::InstagramFacebook],
    'threads' => [Platform::Threads],
    'pinterest' => [Platform::Pinterest],
    'bluesky' => [Platform::Bluesky],
    'mastodon' => [Platform::Mastodon],
    'discord' => [Platform::Discord],
    'google business' => [Platform::GoogleBusiness],
]);

test('every connect and channel route points at an action its controller has', function () {
    $missing = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'app.social.') || str_starts_with((string) $route->getName(), 'app.channels.'))
        ->reject(fn (RoutingRoute $route): bool => method_exists($route->getControllerClass(), $route->getActionMethod()))
        ->map(fn (RoutingRoute $route): string => (string) $route->getName())
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

test('a member without admin rights cannot start a connection', function (string $routeName, string $method, array $platforms, array $payload, string $access) {
    $member = workspaceMember($this->workspace, $access);
    Socialite::shouldReceive('driver')->never();

    $this->actingAs($member)->{$method}(route($routeName), $payload)->assertForbidden();

    expect(PendingConnection::current())->toBeNull()
        ->and(SocialAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with('connect starts')->with(['publishes directly' => 'member', 'needs approval' => 'approval']);

test('a workspace admin who is not the owner can start a connection', function (string $routeName, string $method, array $platforms, array $payload) {
    $admin = workspaceMember($this->workspace, 'admin');
    fakePublicDns();
    Http::fake([
        'https://mastodon.example/api/v1/apps' => Http::response(['client_id' => 'client-id', 'client_secret' => 'client-secret']),
        config('trypost.platforms.bluesky.default_service').'/*' => Http::response(['error' => 'AuthenticationRequired', 'message' => 'Invalid identifier or password'], 401),
    ]);

    $response = $this->actingAs($admin)->{$method}(route($routeName), $payload);

    expect($response->status())->toBeIn([200, 302]);

    match ($routeName) {
        'app.social.telegram.connect' => $response->assertJsonStructure(['code', 'nonce', 'expires_at']),
        'app.social.bluesky.store' => $response->assertSessionHasErrors('password'),
        default => expect(PendingConnection::current()?->workspaceId())->toBe($this->workspace->id),
    };
})->with('connect starts');

test('a disabled network refuses to start a connection, even for the owner', function (string $routeName, string $method, array $platforms, array $payload) {
    foreach ($platforms as $platform) {
        config()->set("trypost.platforms.{$platform}.enabled", false);
    }

    Socialite::shouldReceive('driver')->never();

    $this->actingAs($this->owner)->{$method}(route($routeName), $payload)->assertForbidden();

    expect(PendingConnection::current())->toBeNull();
    Http::assertNothingSent();
})->with('connect starts');

test('a callback without its connect session shows the expired confirmation page', function (string $routeName, array $session) {
    Socialite::shouldReceive('driver')->never();
    session($session);

    $this->actingAs($this->owner)
        ->get(route($routeName, ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', connectRoutePlatform($routeName)));

    $this->get(route('app.social.connect.show', connectRoutePlatform($routeName)))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired'));

    expect(SocialAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with('connect callbacks');

test('a callback for a user who lost the right to manage channels connects nothing', function (string $routeName, array $session) {
    $demoted = workspaceMember($this->workspace, 'member');
    Socialite::shouldReceive('driver')->never();
    startSocialConnect($this->workspace, connectRoutePlatform($routeName));
    session($session);

    $this->actingAs($demoted)
        ->get(route($routeName, ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', connectRoutePlatform($routeName)));

    expect(SocialAccount::query()->count())->toBe(0)
        ->and(socialConnectFailure())->toBe('workspace_not_found');
    Http::assertNothingSent();
})->with('connect callbacks');

test('the confirmation page and finish refuse a user who lost the right to manage channels', function (Platform $platform) {
    $demoted = workspaceMember($this->workspace, 'member');
    startSocialConnect($this->workspace, $platform)->offer([
        PendingConnection::identity($platform, 'identity-1', 'Brand', 'brand', null, 'profile', ['access_token' => 'user-token']),
    ]);

    $this->actingAs($demoted)
        ->get(route('app.social.connect.show', $platform))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'error')
            ->where('reason', __('accounts.connect.errors.workspace_not_found'))
            ->where('identities', [])
        );

    $this->post(route('app.social.connect.finish', $platform), ['identities' => ["{$platform->value}:identity-1"]])
        ->assertSessionHasErrors('identities.0');

    expect(SocialAccount::query()->count())->toBe(0)
        ->and(PendingConnection::current()->identities())->toBe([]);
    Http::assertNothingSent();
})->with('confirmation pages');

test('finish by a user who lost the right after the page loaded connects nothing', function (Platform $platform) {
    $demoted = workspaceMember($this->workspace, 'member');
    startSocialConnect($this->workspace, $platform)->offer([
        PendingConnection::identity($platform, 'identity-1', 'Brand', 'brand', null, 'profile', ['access_token' => 'user-token']),
    ]);

    $this->actingAs($demoted);

    finishSocialConnect($platform)->assertRedirect(route('app.social.connect.show', $platform));

    expect(SocialAccount::query()->count())->toBe(0)
        ->and(socialConnectFailure())->toBe('workspace_not_found')
        ->and(PendingConnection::current()->identities())->toBe([]);
})->with('confirmation pages');

test('a callback whose state does not match the one issued never reaches the provider', function (string $routeName, string $service) {
    $calls = recordConnectProviderCalls($service, 200);
    startSocialConnect($this->workspace, connectRoutePlatform($routeName));
    session(['state' => 'issued-state']);

    $this->actingAs($this->owner)
        ->get(route($routeName, ['code' => 'stolen-code', 'state' => 'forged-state']))
        ->assertRedirect(route('app.social.connect.show', connectRoutePlatform($routeName)));

    expect($calls)->toHaveCount(0)
        ->and(socialConnectFailure())->toBe('error_connecting')
        ->and(SocialAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with('socialite callbacks');

test('a callback replayed after it was used is refused because its state is spent', function (string $routeName, string $service) {
    $calls = recordConnectProviderCalls($service, 200);
    startSocialConnect($this->workspace, connectRoutePlatform($routeName));

    $this->actingAs($this->owner)
        ->get(route($routeName, ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', connectRoutePlatform($routeName)));

    expect($calls)->toHaveCount(0)
        ->and(socialConnectFailure())->toBe('error_connecting')
        ->and(SocialAccount::query()->count())->toBe(0);
})->with('socialite callbacks');

dataset('cancelled consents', [
    'linkedin cancelled login' => ['app.social.linkedin.callback', [], ['error' => 'user_cancelled_login']],
    'linkedin cancelled authorize' => ['app.social.linkedin.callback', [], ['error' => 'user_cancelled_authorize']],
    'x' => ['app.social.x.callback', [], ['error' => 'access_denied']],
    'tiktok' => ['app.social.tiktok.callback', [], ['error' => 'access_denied', 'error_description' => 'User cancelled the authorization']],
    'youtube' => ['app.social.youtube.callback', [], ['error' => 'access_denied']],
    'facebook' => ['app.social.facebook.callback', [], ['error' => 'access_denied', 'error_reason' => 'user_denied']],
    'facebook reason only' => ['app.social.facebook.callback', [], ['error_reason' => 'user_denied']],
    'instagram' => ['app.social.instagram.callback', [], ['error' => 'access_denied', 'error_reason' => 'user_denied']],
    'instagram via facebook' => ['app.social.instagram-facebook.callback', [], ['error' => 'access_denied', 'error_reason' => 'user_denied']],
    'threads' => ['app.social.threads.callback', ['threads_oauth_state' => 'issued-state'], ['error' => 'access_denied', 'error_reason' => 'user_denied']],
    'pinterest' => ['app.social.pinterest.callback', [], ['error' => 'access_denied']],
    'discord' => ['app.social.discord.callback', [], ['error' => 'access_denied']],
    'google business' => ['app.social.google-business.callback', [], ['error' => 'access_denied']],
    'mastodon' => ['app.social.mastodon.callback', [
        'mastodon_instance' => 'https://mastodon.example',
        'mastodon_client_id' => 'client-id',
        'mastodon_client_secret' => 'client-secret',
        'mastodon_oauth_state' => 'issued-state',
    ], ['error' => 'access_denied']],
]);

test('a user who cancels the consent screen sees the cancelled state without a token exchange', function (string $routeName, array $session, array $query) {
    Socialite::shouldReceive('driver')->never();
    startSocialConnect($this->workspace, connectRoutePlatform($routeName), null, '/settings/workspace/channels');
    session(['state' => 'issued-state', ...$session]);

    $this->actingAs($this->owner)
        ->get(route($routeName, [...$query, 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', connectRoutePlatform($routeName)));

    $this->get(route('app.social.connect.show', connectRoutePlatform($routeName)))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'cancelled')
            ->where('backUrl', route('app.workspace.channels'))
        );

    expect(SocialAccount::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with('cancelled consents');

test('a provider error that is not a cancel still reports a failed connect', function () {
    $calls = recordConnectProviderCalls('x');
    startSocialConnect($this->workspace, Platform::X);
    session(['state' => 'issued-state']);

    $this->actingAs($this->owner)
        ->get(route('app.social.x.callback', ['error' => 'server_error', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::X));

    expect($calls)->toHaveCount(1)
        ->and(socialConnectFailure())->toBe('error_connecting')
        ->and(SocialAccount::query()->count())->toBe(0);
});

test('a reconnect whose identity is already seated under the other instagram variant says the network is taken', function () {
    $viaFacebook = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'ig-1',
        'access_token' => 'facebook-token',
        'status' => Status::TokenExpired,
    ]);
    $direct = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-1',
        'access_token' => 'direct-token',
    ]);
    startSocialConnect($this->workspace, Platform::Instagram, $viaFacebook);

    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('ig-1');
    $socialiteUser->shouldReceive('getNickname')->andReturn('brand');
    $socialiteUser->shouldReceive('getName')->andReturn('Brand');
    $socialiteUser->shouldReceive('getAvatar')->andReturn(null);
    $socialiteUser->token = 'fresh-token';
    $socialiteUser->refreshToken = null;
    $socialiteUser->expiresIn = 5184000;
    $socialiteUser->user = ['account_type' => 'BUSINESS'];

    Socialite::shouldReceive('driver')->with('instagram')->andReturn(Mockery::mock(['user' => $socialiteUser]));

    $this->actingAs($this->owner)
        ->get(route('app.social.instagram.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::Instagram));

    finishSocialConnect(Platform::Instagram)->assertRedirect(route('app.social.connect.show', Platform::Instagram));

    expect(socialConnectFailure())->toBe('network_taken')
        ->and($viaFacebook->fresh())
        ->platform->toBe(Platform::InstagramFacebook)
        ->access_token->toBe('facebook-token')
        ->status->toBe(Status::TokenExpired)
        ->and($direct->fresh()->access_token)->toBe('direct-token');
});

test('a successful token refresh never promotes a lost connection back to connected', function () {
    $account = SocialAccount::factory()->x()->tokenExpired()->create([
        'workspace_id' => $this->workspace->id,
        'refresh_token' => 'still-valid-refresh-token',
        'token_expires_at' => now()->addMinutes(10),
    ]);

    Http::fake([
        config('trypost.platforms.x.api').'/oauth2/token' => Http::response([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 7200,
        ]),
    ]);

    (new RefreshSocialToken($account))->handle(app(ConnectionVerifier::class));

    expect($account->fresh())
        ->access_token->toBe('rotated-access-token')
        ->status->toBe(Status::TokenExpired);
});

test('a connection that stops forgets the tokens it was offered', function (string $routeName, array $session, array $query) {
    $platform = connectRoutePlatform($routeName);
    startSocialConnect($this->workspace, $platform)->offer([
        PendingConnection::identity($platform, 'identity-1', 'Brand', 'brand', null, 'profile', ['access_token' => 'user-token']),
    ]);
    session(['state' => 'issued-state', ...$session]);

    $this->actingAs($this->owner)->get(route($routeName, [...$query, 'state' => 'issued-state']));

    expect(socialConnectFailure())->toBe('cancelled')
        ->and(PendingConnection::current()->identities())->toBe([])
        ->and(json_encode(session()->all()))->not->toContain('user-token');
})->with('cancelled consents');

test('starting a new connection drops the identities an abandoned one left', function (string $connectRoute, Platform $platform) {
    startSocialConnect($this->workspace, $platform)->offer([
        PendingConnection::identity($platform, 'identity-1', 'Brand', 'brand', null, 'page', ['access_token' => 'user-token']),
    ]);
    Socialite::shouldReceive('driver')->andReturn(Mockery::mock(['redirect' => redirect('https://provider.example/authorize')])->shouldIgnoreMissing(Mockery::self()));

    $this->actingAs($this->owner)->get(route($connectRoute));

    expect(PendingConnection::current()->identities())->toBe([])
        ->and(json_encode(session()->all()))->not->toContain('user-token');
})->with([
    'facebook' => ['app.social.facebook.connect', Platform::Facebook],
    'instagram via facebook' => ['app.social.instagram-facebook.connect', Platform::InstagramFacebook],
    'linkedin' => ['app.social.linkedin.connect', Platform::LinkedIn],
    'google business' => ['app.social.google-business.connect', Platform::GoogleBusiness],
]);

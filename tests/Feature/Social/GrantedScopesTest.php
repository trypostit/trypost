<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessPublisher;
use App\Support\Social\PendingConnection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
 * @param  array<int, string>  $approvedScopes  in the shape the network's Socialite provider builds them
 */
function grantedScopesSocialiteUser(string $id, array $approvedScopes): SocialiteUser
{
    $user = Mockery::mock(SocialiteUser::class);
    $user->shouldReceive('getId')->andReturn($id);
    $user->shouldReceive('getNickname')->andReturn('brand');
    $user->shouldReceive('getName')->andReturn('Brand');
    $user->shouldReceive('getAvatar')->andReturn(null);
    $user->token = 'access-token';
    $user->refreshToken = 'refresh-token';
    $user->expiresIn = 3600;
    $user->approvedScopes = $approvedScopes;
    $user->user = ['account_type' => 'BUSINESS'];

    return $user;
}

function grantedScopesFakeSocialite(string $driver, SocialiteUser $user): void
{
    $provider = Mockery::mock();
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);
}

/**
 * Each network: the callback route, how its provider reports a grant, the
 * scopes stored for it, and the session/fakes the callback needs.
 *
 * @return array<string, array{route: string, platform: Platform, arrange: Closure(array<int, string>): void}>
 */
function grantedScopesNetworks(): array
{
    return [
        'x' => [
            'route' => 'app.social.x.callback',
            'platform' => Platform::X,
            'arrange' => function (array $granted): void {
                grantedScopesFakeSocialite('x', grantedScopesSocialiteUser('x-1', $granted));
                Http::fake([config('trypost.platforms.x.api').'/*' => Http::response([], 200)]);
            },
        ],
        'tiktok' => [
            'route' => 'app.social.tiktok.callback',
            'platform' => Platform::TikTok,
            'arrange' => fn (array $granted) => grantedScopesFakeSocialite('tiktok', grantedScopesSocialiteUser('tiktok-1', $granted)),
        ],
        'pinterest' => [
            'route' => 'app.social.pinterest.callback',
            'platform' => Platform::Pinterest,
            'arrange' => fn (array $granted) => grantedScopesFakeSocialite('pinterest', grantedScopesSocialiteUser('pin-1', [implode(' ', $granted)])),
        ],
        'youtube' => [
            'route' => 'app.social.youtube.callback',
            'platform' => Platform::YouTube,
            'arrange' => function (array $granted): void {
                grantedScopesFakeSocialite('google', grantedScopesSocialiteUser('google-1', $granted));
                Http::fake([config('trypost.platforms.youtube.data_api').'/channels*' => Http::response([
                    'items' => [['id' => 'UC-1', 'snippet' => ['title' => 'Brand', 'customUrl' => '@brand', 'thumbnails' => ['default' => ['url' => null]]]]],
                ])]);
            },
        ],
        'google business' => [
            'route' => 'app.social.google-business.callback',
            'platform' => Platform::GoogleBusiness,
            'arrange' => function (array $granted): void {
                grantedScopesFakeSocialite('google-business', grantedScopesSocialiteUser('google-1', $granted));
                test()->mock(GoogleBusinessPublisher::class, function ($mock) {
                    $mock->shouldReceive('fetchLocations')->andReturn([
                        ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown', 'address' => null],
                    ]);
                    $mock->shouldReceive('fetchLocationPhoto')->andReturn(null);
                });
            },
        ],
        'instagram' => [
            'route' => 'app.social.instagram.callback',
            'platform' => Platform::Instagram,
            'arrange' => fn (array $granted) => grantedScopesFakeSocialite('instagram', grantedScopesSocialiteUser('ig-1', [implode(',', $granted)])),
        ],
        'threads' => [
            'route' => 'app.social.threads.callback',
            'platform' => Platform::Threads,
            'arrange' => function (array $granted): void {
                session(['threads_oauth_state' => 'issued-state']);
                Http::fake([
                    config('trypost.platforms.threads.auth_api').'/oauth/access_token' => Http::response(['access_token' => 'short-token', 'user_id' => '42']),
                    config('trypost.platforms.threads.auth_api').'/access_token*' => Http::response(['access_token' => 'long-token', 'expires_in' => 5184000]),
                    config('trypost.platforms.threads.graph_api').'/debug_token*' => Http::response(['data' => ['is_valid' => true, 'scopes' => $granted, 'user_id' => '42']]),
                    config('trypost.platforms.threads.graph_api').'/42*' => Http::response(['id' => '42', 'username' => 'brand', 'name' => 'Brand']),
                ]);
            },
        ],
        'mastodon' => [
            'route' => 'app.social.mastodon.callback',
            'platform' => Platform::Mastodon,
            'arrange' => function (array $granted): void {
                session([
                    'mastodon_instance' => 'https://mastodon.example',
                    'mastodon_client_id' => 'client-id',
                    'mastodon_client_secret' => 'client-secret',
                    'mastodon_oauth_state' => 'issued-state',
                ]);
                Http::fake([
                    'https://mastodon.example/oauth/token' => Http::response(['access_token' => 'token', 'scope' => implode(' ', $granted)]),
                    'https://mastodon.example/api/v1/accounts/verify_credentials' => Http::response(['id' => '7', 'acct' => 'brand', 'username' => 'brand']),
                ]);
            },
        ],
    ];
}

dataset('granted scopes', [
    'x' => ['x', ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'], 'tweet.write', 'media.write'],
    'tiktok' => ['tiktok', ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.publish', 'video.upload', 'video.list'], 'video.publish', 'video.list'],
    'pinterest' => ['pinterest', ['boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read'], 'pins:write', 'pins:read'],
    'youtube' => ['youtube', [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/youtube.force-ssl',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
    ], 'https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/yt-analytics.readonly'],
    'google business' => ['google business', [
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/business.manage',
    ], 'https://www.googleapis.com/auth/business.manage', 'https://www.googleapis.com/auth/userinfo.email'],
    'instagram' => ['instagram', ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights'], 'instagram_business_content_publish', 'instagram_business_manage_insights'],
    'threads' => ['threads', ['threads_basic', 'threads_content_publish', 'threads_manage_insights', 'threads_manage_replies'], 'threads_content_publish', 'threads_manage_insights'],
    'mastodon' => ['mastodon', ['read:accounts', 'read:statuses', 'write:statuses', 'write:media'], 'write:statuses', 'read:statuses'],
]);

function grantedScopesCallback(string $network, array $granted): array
{
    $definition = grantedScopesNetworks()[$network];
    startSocialConnect(test()->workspace, $definition['platform']);
    ($definition['arrange'])($granted);

    return $definition;
}

test('a login that granted every scope connects with the granted scopes stored', function (string $network, array $granted) {
    $definition = grantedScopesCallback($network, $granted);

    $this->actingAs($this->owner)
        ->get(route($definition['route'], ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', $definition['platform']));

    assertFinishedOnChannel(finishSocialConnect($definition['platform']));

    expect(SocialAccount::query()->sole())
        ->platform->toBe($definition['platform'])
        ->scopes->toEqualCanonicalizing($granted);
})->with('granted scopes');

test('a login that left out the publish scope is refused and stores nothing', function (string $network, array $granted, string $essential) {
    $definition = grantedScopesCallback($network, array_values(array_diff($granted, [$essential])));

    $this->actingAs($this->owner)
        ->get(route($definition['route'], ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', $definition['platform']));

    $this->get(route('app.social.connect.show', $definition['platform']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'missing_permission')
            ->where('identities', [])
        );

    expect(SocialAccount::query()->count())->toBe(0)
        ->and(PendingConnection::current()->identities())->toBe([]);
})->with('granted scopes');

test('a login that left out an optional scope connects and records it as missing', function (string $network, array $granted, string $essential, string $optional) {
    $definition = grantedScopesCallback($network, array_values(array_diff($granted, [$optional])));

    $this->actingAs($this->owner)
        ->get(route($definition['route'], ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', $definition['platform']));

    finishSocialConnect($definition['platform'])->assertRedirect();

    expect(SocialAccount::query()->sole()->scopes)
        ->toContain($essential)
        ->not->toContain($optional);
})->with('granted scopes');

test('a reconnect that left out the publish scope keeps the account as it was', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => 'UC-1',
        'access_token' => 'old-token',
        'scopes' => ['https://www.googleapis.com/auth/youtube.upload'],
    ]);
    $definition = grantedScopesCallback('youtube', ['https://www.googleapis.com/auth/youtube.readonly']);
    startSocialConnect($this->workspace, Platform::YouTube, $account);

    $this->actingAs($this->owner)
        ->get(route($definition['route'], ['code' => 'code-1']))
        ->assertRedirect(route('app.social.connect.show', Platform::YouTube));

    expect(socialConnectFailure())->toBe('publish_permission_missing');

    expect($account->fresh())
        ->access_token->toBe('old-token')
        ->scopes->toBe(['https://www.googleapis.com/auth/youtube.upload']);
});

test('a network that reports no scopes keeps the requested ones', function () {
    grantedScopesCallback('threads', []);
    Http::fake([config('trypost.platforms.threads.graph_api').'/debug_token*' => Http::response(['error' => ['message' => 'Unsupported']], 400)]);

    $this->actingAs($this->owner)
        ->get(route('app.social.threads.callback', ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));

    finishSocialConnect(Platform::Threads)->assertRedirect();

    expect(SocialAccount::query()->sole()->scopes)
        ->toBe(['threads_basic', 'threads_content_publish', 'threads_manage_insights', 'threads_manage_replies']);
});

test('threads reads the granted scopes by inspecting the long-lived user token on the threads graph', function () {
    grantedScopesCallback('threads', ['threads_basic', 'threads_content_publish']);

    $this->actingAs($this->owner)
        ->get(route('app.social.threads.callback', ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));

    finishSocialConnect(Platform::Threads)->assertRedirect();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), config('trypost.platforms.threads.graph_api').'/debug_token')
        && data_get($request->data(), 'input_token') === 'long-token'
        && data_get($request->data(), 'access_token') === 'long-token');
    expect(SocialAccount::query()->sole()->scopes)->toBe(['threads_basic', 'threads_content_publish']);
});

test('google business keeps the granted scopes through the confirmation page', function () {
    grantedScopesFakeSocialite('google-business', grantedScopesSocialiteUser('google-1', [
        'https://www.googleapis.com/auth/business.manage',
    ]));
    $this->mock(GoogleBusinessPublisher::class, function ($mock) {
        $mock->shouldReceive('fetchLocations')->andReturn([
            ['id' => 'accounts/1/locations/2', 'account_name' => 'accounts/1', 'location_name' => 'locations/2', 'title' => 'Downtown', 'address' => null],
            ['id' => 'accounts/1/locations/3', 'account_name' => 'accounts/1', 'location_name' => 'locations/3', 'title' => 'Uptown', 'address' => null],
        ]);
        $mock->shouldReceive('fetchLocationPhoto')->andReturn(null);
    });
    startSocialConnect($this->workspace, Platform::GoogleBusiness);

    $this->actingAs($this->owner)
        ->get(route('app.social.google-business.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::GoogleBusiness));

    finishSocialConnect(Platform::GoogleBusiness, ['google_business:accounts/1/locations/3'])->assertRedirect();

    expect(SocialAccount::query()->sole()->scopes)->toBe(['https://www.googleapis.com/auth/business.manage']);
});

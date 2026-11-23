<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessPublisher;
use App\Support\ChannelPermissions;
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

dataset('meta publish permissions', [
    'Facebook' => [Platform::Facebook, 'pages_manage_posts'],
    'Instagram via Facebook' => [Platform::InstagramFacebook, 'instagram_content_publish'],
]);

function prepareLegacyMetaCallback(Platform $platform): void
{
    startSocialConnect(test()->workspace, $platform);
    session()->forget(PendingConnection::SESSION_KEY.'.requested_scopes');
    test()->actingAs(test()->owner);

    $driver = Mockery::mock();
    $driver->shouldReceive('usingGraphVersion', 'redirectUrl')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn(grantedScopesSocialiteUser('facebook-user', []));
    Socialite::shouldReceive('driver')->with('facebook')->andReturn($driver);

    $graph = config("trypost.platforms.{$platform->value}.graph_api");
    Http::fake([
        "{$graph}/me?*" => Http::response(['id' => 'facebook-user']),
        "{$graph}/me/accounts*" => Http::response(['data' => [[
            'id' => 'page-one', 'name' => 'Page One', 'access_token' => 'page-token',
            'instagram_business_account' => ['id' => 'ig-one'],
        ]]]),
        "{$graph}/ig-one*" => Http::response(['id' => 'ig-one', 'username' => 'brand', 'name' => 'Brand']),
    ]);
}

test('a pre-deploy Meta callback stores only explicitly granted scopes when publishing was approved', function (Platform $platform, string $publishScope) {
    prepareLegacyMetaCallback($platform);
    Http::fake(['*/me/permissions*' => Http::response(['data' => [
        ['permission' => 'pages_show_list', 'status' => 'granted'],
        ['permission' => $publishScope, 'status' => 'granted'],
        ['permission' => 'business_management', 'status' => 'declined'],
    ]])]);

    $this->get(route("app.social.{$platform->value}.callback"))->assertRedirect(route('app.social.connect.show', $platform));
    finishSocialConnect($platform)->assertRedirect();

    expect($this->workspace->socialAccounts()->sole()->scopes)->toBe(['pages_show_list', $publishScope]);
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/me/permissions')))->toHaveCount(1);
})->with('meta publish permissions');

test('a pre-deploy Meta callback refuses an explicitly declined or expired publishing permission', function (Platform $platform, string $publishScope, string $status) {
    prepareLegacyMetaCallback($platform);
    Http::fake(['*/me/permissions*' => Http::response(['data' => [['permission' => $publishScope, 'status' => $status]]])]);

    $this->get(route("app.social.{$platform->value}.callback"))->assertRedirect(route('app.social.connect.show', $platform));

    expect(socialConnectFailure())->toBe('publish_permission_missing');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/me/accounts'));
    $this->assertDatabaseCount('social_accounts', 0);
})->with('meta publish permissions')->with(['declined', 'expired']);

test('a pre-deploy Meta callback restarts consent when the publishing grant is unknown', function (Platform $platform, string $publishScope, string $response) {
    prepareLegacyMetaCallback($platform);
    Http::fake(['*/me/permissions*' => match ($response) {
        'partial' => Http::response(['data' => [['permission' => 'pages_show_list', 'status' => 'granted']]]),
        'unknown status' => Http::response(['data' => [['permission' => $publishScope, 'status' => 'unrecognized']]]),
        'http failure' => Http::response([], 503),
        'connection failure' => Http::failedConnection(),
    }]);
    $restart = route("app.social.{$platform->value}.connect", ['return_to' => PendingConnection::current()->returnPath()]);

    $this->get(route("app.social.{$platform->value}.callback"))->assertRedirect($restart);

    expect(socialConnectFailure())->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/me/accounts'));
    $this->assertDatabaseCount('social_accounts', 0);
})->with('meta publish permissions')->with(['partial', 'unknown status', 'http failure', 'connection failure']);

test('a pre-deploy Meta callback reads the next page before persisting the history grant', function (Platform $platform, string $publishScope) {
    prepareLegacyMetaCallback($platform);
    $next = config("trypost.platforms.{$platform->value}.graph_api").'/me/permissions?after=page-two';
    Http::fake([
        $next => Http::response(['data' => [['permission' => 'pages_read_engagement', 'status' => 'granted']]]),
        '*/me/permissions*' => Http::response([
            'data' => [['permission' => $publishScope, 'status' => 'granted']],
            'paging' => ['next' => $next],
        ]),
    ]);

    $this->get(route("app.social.{$platform->value}.callback"))->assertRedirect(route('app.social.connect.show', $platform));
    finishSocialConnect($platform)->assertRedirect();

    expect($this->workspace->socialAccounts()->sole()->scopes)->toBe([$publishScope, 'pages_read_engagement']);
})->with('meta publish permissions');

test('a pre-deploy Meta callback honors an explicit refusal on a later permissions page', function () {
    prepareLegacyMetaCallback(Platform::Facebook);
    $next = config('trypost.platforms.facebook.graph_api').'/me/permissions?after=page-two';
    Http::fake([
        $next => Http::response(['data' => [['permission' => 'pages_manage_posts', 'status' => 'expired']]]),
        '*/me/permissions*' => Http::response([
            'data' => [['permission' => 'pages_read_engagement', 'status' => 'granted']],
            'paging' => ['next' => $next],
        ]),
    ]);

    $this->get(route('app.social.facebook.callback'))->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(socialConnectFailure())->toBe('publish_permission_missing');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/me/accounts'));
});

test('an incomplete legacy Meta permissions walk keeps explicit refusals and never stores a partial grant', function (string $status, bool $outOfTime) {
    prepareLegacyMetaCallback(Platform::Facebook);
    $next = config('trypost.platforms.facebook.graph_api').'/me/permissions?after=page-two';
    if ($outOfTime) {
        config(['trypost.meta_page_walk_seconds' => 0]);
    }
    Http::fake([
        $next => Http::response([], 503),
        '*/me/permissions*' => Http::response([
            'data' => [['permission' => 'pages_manage_posts', 'status' => $status]],
            'paging' => ['next' => $next],
        ]),
    ]);

    $restart = route('app.social.facebook.connect', ['return_to' => PendingConnection::current()->returnPath()]);
    $response = $this->get(route('app.social.facebook.callback'));
    if ($status === 'declined') {
        $response->assertRedirect(route('app.social.connect.show', Platform::Facebook));
        expect(socialConnectFailure())->toBe('publish_permission_missing');
    } else {
        $response->assertRedirect($restart);
        expect(socialConnectFailure())->toBeNull();
    }
    if ($outOfTime) {
        Http::assertNotSent(fn (Request $request): bool => $request->url() === $next);
    } else {
        Http::assertSent(fn (Request $request): bool => $request->url() === $next);
    }
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/me/accounts'));
    $this->assertDatabaseCount('social_accounts', 0);
})->with(['granted', 'declined'])->with(['budget exhausted' => true, 'second page fails' => false]);

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
        'https://www.googleapis.com/auth/yt-analytics.readonly',
    ], 'https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/yt-analytics.readonly'],
    'google business' => ['google business', [
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/business.manage',
    ], 'https://www.googleapis.com/auth/business.manage', 'https://www.googleapis.com/auth/userinfo.email'],
    'instagram' => ['instagram', ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights'], 'instagram_business_content_publish', 'instagram_business_manage_insights'],
    'threads' => ['threads', ['threads_basic', 'threads_content_publish', 'threads_manage_insights'], 'threads_content_publish', 'threads_manage_insights'],
    'mastodon' => ['mastodon', ['read:accounts', 'read:statuses', 'write:statuses', 'write:media'], 'write:statuses', 'read:statuses'],
]);

function grantedScopesCallback(string $network, array $granted): array
{
    $definition = grantedScopesNetworks()[$network];
    startSocialConnect(test()->workspace, $definition['platform']);
    ($definition['arrange'])($granted);

    return $definition;
}

test('a login that granted the required and optional scopes connects with the granted scopes stored', function (string $network, array $granted) {
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

test('a YouTube grant with an alternative upload scope connects and schedules a short', function (string $scope) {
    $grant = ['https://www.googleapis.com/auth/'.$scope];
    $definition = grantedScopesCallback('youtube', $grant);
    $this->actingAs($this->owner)->get(route($definition['route'], ['code' => 'code-1']))
        ->assertRedirect(route('app.social.connect.show', Platform::YouTube));
    expect(socialConnectFailure())->toBeNull();
    assertFinishedOnChannel(finishSocialConnect(Platform::YouTube));
    $channel = SocialAccount::query()->sole();
    expect($channel->scopes)->toEqualCanonicalizing($grant)
        ->and(ChannelPermissions::missing($channel))->toBe([]);

    $video = Media::factory()->video()->temporaryUpload($this->workspace)->create([
        'meta' => ['duration' => 20, 'width' => 1080, 'height' => 1920],
    ]);
    $this->post(route('app.posts.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'New short',
        'media' => [MediaItem::fromMedia($video)->toArray()],
        'destinations' => [['social_account_id' => $channel->id, 'content_type' => ContentType::YouTubeShort->value]],
    ])->assertSessionHasNoErrors();

    expect(Post::query()->whereBelongsTo($channel, 'socialAccount')->sole()->status)->toBe(Status::Scheduled);
})->with(['youtube.force-ssl', 'youtube', 'youtubepartner']);

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

test('a pre-deploy consent session with no reported grant restarts consent and then connects', function () {
    grantedScopesCallback('threads', []);
    session()->forget(PendingConnection::SESSION_KEY.'.requested_scopes');
    $start = route('app.social.threads.connect', ['return_to' => PendingConnection::current()->returnPath()]);

    $this->actingAs($this->owner)
        ->get(route('app.social.threads.callback', ['code' => 'first', 'state' => 'issued-state']))
        ->assertRedirect($start);
    expect(SocialAccount::query()->exists())->toBeFalse();

    $this->get($start)->assertRedirectContains('/oauth/authorize');
    $this->get(route('app.social.threads.callback', ['code' => 'second', 'state' => session('threads_oauth_state')]))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));
    finishSocialConnect(Platform::Threads)->assertRedirect();

    expect(SocialAccount::query()->sole()->scopes)->toBe(['threads_basic', 'threads_content_publish', 'threads_manage_insights']);
});

test('a pre-deploy consent session never restarts a reconnect in a different workspace', function () {
    grantedScopesCallback('tiktok', []);
    session()->forget(PendingConnection::SESSION_KEY.'.requested_scopes');
    $other = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->owner->update(['current_workspace_id' => $other->id]);

    $this->actingAs($this->owner)->get(route('app.social.tiktok.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::TikTok));

    expect(socialConnectFailure())->toBe('session_expired')
        ->and(SocialAccount::query()->exists())->toBeFalse();
});

test('a pre-deploy consent session honors the grant the provider reports', function (array $grant, bool $publishable) {
    grantedScopesCallback('tiktok', $grant);
    session()->forget(PendingConnection::SESSION_KEY.'.requested_scopes');

    $this->actingAs($this->owner)->get(route('app.social.tiktok.callback'))->assertRedirect(route('app.social.connect.show', Platform::TikTok));

    if ($publishable) {
        finishSocialConnect(Platform::TikTok)->assertRedirect();
        expect(SocialAccount::query()->sole()->scopes)->toBe($grant);

        return;
    }

    expect(socialConnectFailure())->toBe('publish_permission_missing')
        ->and(SocialAccount::query()->exists())->toBeFalse();
})->with([
    'publish granted' => [['user.info.basic', 'video.publish'], true],
    'publish declined' => [['user.info.basic'], false],
]);

test('a network that reports no scopes keeps the requested ones', function () {
    grantedScopesCallback('threads', []);
    Http::fake([config('trypost.platforms.threads.graph_api').'/debug_token*' => Http::response(['error' => ['message' => 'Unsupported']], 400)]);

    $this->actingAs($this->owner)
        ->get(route('app.social.threads.callback', ['code' => 'code-1', 'state' => 'issued-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::Threads));

    finishSocialConnect(Platform::Threads)->assertRedirect();

    expect(SocialAccount::query()->sole()->scopes)
        ->toBe(['threads_basic', 'threads_content_publish', 'threads_manage_insights']);
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

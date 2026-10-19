<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\PostHog\TrackSocialAccountConnected;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));

    Http::fake([
        config('trypost.platforms.x.api').'/users/me*' => Http::response(['data' => ['id' => 'x-1']]),
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function offeredFacebookPage(string $id, string $name, array $attributes = []): array
{
    return PendingConnection::identity(
        Platform::Facebook,
        $id,
        $name,
        null,
        null,
        'page',
        [
            'display_name' => $name,
            'access_token' => "page-token-{$id}",
            'refresh_token' => null,
            'token_expires_at' => null,
            'scopes' => ['pages_manage_posts'],
            'meta' => ['page_id' => $id],
            ...$attributes,
        ],
    );
}

function fakeXLogin(string $id = 'x-1', string $nickname = 'brand'): void
{
    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->shouldReceive('getId')->andReturn($id);
    $socialUser->shouldReceive('getNickname')->andReturn($nickname);
    $socialUser->shouldReceive('getName')->andReturn('Brand');
    $socialUser->shouldReceive('getAvatar')->andReturn(null);
    $socialUser->token = 'x-access-token';
    $socialUser->refreshToken = 'x-refresh-token';
    $socialUser->expiresIn = 7200;
    $socialUser->approvedScopes = ['tweet.read', 'tweet.write', 'users.read'];

    Socialite::shouldReceive('driver')->with('x')->andReturn(Mockery::mock(['user' => $socialUser]));
}

test('the start route remembers the workspace, the reconnect and a whitelisted return page', function () {
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.social.x.connect', [
            'reconnect' => $account->id,
            'return_to' => "/channels/{$account->id}/settings",
        ]))
        ->assertRedirect();

    $pending = PendingConnection::current();

    expect($pending->platform())->toBe(Platform::X)
        ->and($pending->workspaceId())->toBe($this->workspace->id)
        ->and($pending->reconnectId())->toBe($account->id)
        ->and($pending->returnUrl())->toBe(route('app.channels.settings', $account->id));
});

test('a return target outside the app pages falls back to the channels page', function (string $returnTo) {
    $this->actingAs($this->user)->get(route('app.social.x.connect', ['return_to' => $returnTo]))->assertRedirect();

    expect(PendingConnection::current()->returnUrl())->toBe(route('app.workspace.channels'));
})->with([
    'another host' => 'https://evil.example/steal',
    'protocol relative' => '//evil.example/steal',
    'a connect route' => '/connect/x',
    'a callback' => '/accounts/x/callback',
    'an unknown path' => '/no/such/page',
    'not a path' => 'javascript:alert(1)',
]);

test('the confirmation page shows the identity without its tokens and pre-checks a single one', function () {
    startSocialConnect($this->workspace, Platform::X);
    fakeXLogin();

    $this->actingAs($this->user)
        ->get(route('app.social.x.callback'))
        ->assertRedirect(route('app.social.connect.show', Platform::X));

    $response = $this->get(route('app.social.connect.show', Platform::X));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('accounts/ConnectFinish')
        ->where('platform', 'x')
        ->where('state', 'select')
        ->where('reconnecting', false)
        ->has('identities', 1)
        ->where('identities.0.key', 'x:x-1')
        ->where('identities.0.name', 'Brand')
        ->where('identities.0.type', 'profile')
        ->where('identities.0.locked', false)
        ->where('backUrl', route('app.workspace.channels'))
    );

    expect($response->getContent())->not->toContain('x-access-token')
        ->not->toContain('x-refresh-token');
});

test('an identity already connected on this platform is shown locked and cannot be picked', function () {
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'x-1',
        'access_token' => 'old-token',
        'status' => Status::TokenExpired,
    ]);

    startSocialConnect($this->workspace, Platform::X);
    fakeXLogin();

    $this->actingAs($this->user)->get(route('app.social.x.callback'))->assertRedirect();

    $this->get(route('app.social.connect.show', Platform::X))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'select')
            ->where('identities.0.key', 'x:x-1')
            ->where('identities.0.locked', true)
        );

    finishSocialConnect(Platform::X, ['x:x-1'])
        ->assertSessionHasErrors(['identities.0' => __('accounts.connect.errors.identity_connected')]);

    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['x:x-1']], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['identities.0' => __('accounts.connect.errors.identity_connected')]);

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('old-token')
        ->and(PendingConnection::current()?->isReady())->toBeTrue();
});

test('finish connects only the identities picked among those offered', function () {
    startSocialConnect($this->workspace, Platform::Facebook)->offer([
        offeredFacebookPage('p1', 'One'),
        offeredFacebookPage('p2', 'Two'),
        offeredFacebookPage('p3', 'Three'),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::Facebook))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'select')->has('identities', 3));

    assertFinishedOnChannel(finishSocialConnect(Platform::Facebook, ['facebook:p1', 'facebook:p3']))
        ->assertInertiaFlash('connectedChannel.created', true);

    expect($this->workspace->socialAccounts()->pluck('platform_user_id')->sort()->values()->all())->toBe(['p1', 'p3'])
        ->and(PendingConnection::current())->toBeNull();
});

test('finish refuses an identity the network did not offer and connects nothing', function (array $identities) {
    startSocialConnect($this->workspace, Platform::Facebook)->offer([offeredFacebookPage('p1', 'One')]);

    $this->actingAs($this->user)
        ->from(route('app.social.connect.show', Platform::Facebook))
        ->post(route('app.social.connect.finish', Platform::Facebook), ['identities' => $identities])
        ->assertSessionHasErrors();

    $this->assertDatabaseCount('social_accounts', 0);
})->with([
    'unknown page' => [['facebook:intruder']],
    'other platform' => [['instagram:p1']],
    'none picked' => [[]],
    'offered plus unknown' => [['facebook:p1', 'facebook:intruder']],
    'duplicated' => [['facebook:p1', 'facebook:p1']],
]);

test('finish after the pending connection expired connects nothing and shows the expired state', function () {
    startSocialConnect($this->workspace, Platform::Facebook)->offer([offeredFacebookPage('p1', 'One')]);

    $this->travel(PendingConnection::TTL_MINUTES + 1)->minutes();

    $this->actingAs($this->user)
        ->post(route('app.social.connect.finish', Platform::Facebook), ['identities' => ['facebook:p1']])
        ->assertSessionHasErrors('identities.0');

    $this->get(route('app.social.connect.show', Platform::Facebook))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired')->where('identities', []));

    $this->assertDatabaseCount('social_accounts', 0);
});

test('a pending connection of another network is not finished from this page', function () {
    startSocialConnect($this->workspace, Platform::Facebook)->offer([offeredFacebookPage('p1', 'One')]);

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::X))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'expired'));

    $this->post(route('app.social.connect.finish', Platform::X), ['identities' => ['facebook:p1']])
        ->assertRedirect(route('app.social.connect.show', Platform::X));

    $this->assertDatabaseCount('social_accounts', 0);
});

test('a second finish after the first one does not connect again', function () {
    startSocialConnect($this->workspace, Platform::Facebook)->offer([offeredFacebookPage('p1', 'One')]);

    $this->actingAs($this->user);

    assertFinishedOnChannel(finishSocialConnect(Platform::Facebook, ['facebook:p1']));

    $this->post(route('app.social.connect.finish', Platform::Facebook), ['identities' => ['facebook:p1']])
        ->assertSessionHasErrors('identities.0');

    expect($this->workspace->socialAccounts()->count())->toBe(1);
});

test('finish re-checks that the user may still manage the workspace accounts', function (string $access) {
    $member = workspaceMember($this->workspace, $access);

    startSocialConnect($this->workspace, Platform::Facebook)->offer([offeredFacebookPage('p1', 'One')]);

    $this->actingAs($member);

    finishSocialConnect(Platform::Facebook, ['facebook:p1'])
        ->assertRedirect(route('app.social.connect.show', Platform::Facebook));

    expect(socialConnectFailure())->toBe('workspace_not_found');
    $this->assertDatabaseCount('social_accounts', 0);
})->with(['member', 'approval']);

test('a workspace admin who is not the owner can finish a connection', function () {
    $admin = workspaceMember($this->workspace, 'admin');

    startSocialConnect($this->workspace, Platform::Facebook)->offer([offeredFacebookPage('p1', 'One')]);

    $this->actingAs($admin);

    assertFinishedOnChannel(finishSocialConnect(Platform::Facebook, ['facebook:p1']));

    expect($this->workspace->socialAccounts()->count())->toBe(1);
});

test('finish on a network switched off since the start is refused', function () {
    startSocialConnect($this->workspace, Platform::Facebook)->offer([offeredFacebookPage('p1', 'One')]);
    config()->set('trypost.platforms.facebook.enabled', false);

    $this->actingAs($this->user);

    finishSocialConnect(Platform::Facebook, ['facebook:p1'])->assertForbidden();

    $this->assertDatabaseCount('social_accounts', 0);
});

test('finish lands on the connected channel whatever page the connection started from', function (string $route, Closure $parameters) {
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $url = route($route, $parameters($account));

    startSocialConnect($this->workspace, Platform::Facebook, null, parse_url($url, PHP_URL_PATH))
        ->offer([offeredFacebookPage('p1', 'One')]);

    $this->actingAs($this->user);

    $response = finishSocialConnect(Platform::Facebook);

    $channel = $this->workspace->socialAccounts()->where('platform', Platform::Facebook)->sole();

    assertFinishedOnChannel($response, $channel);
})->with([
    'channels page' => ['app.workspace.channels', fn () => []],
    'channel settings' => ['app.channels.settings', fn (SocialAccount $account) => [$account->id]],
    'calendar' => ['app.calendar', fn () => ['view' => 'week']],
    'channel queue' => ['app.channels.publish', fn (SocialAccount $account) => [$account->id]],
]);

test('the confirmation page sends close and back to the page the connection started from', function (string $route, Closure $parameters) {
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $url = route($route, $parameters($account));

    startSocialConnect($this->workspace, Platform::Facebook, null, parse_url($url, PHP_URL_PATH))
        ->offer([offeredFacebookPage('p1', 'One')]);

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::Facebook))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('backUrl', $url));
})->with([
    'channels page' => ['app.workspace.channels', fn () => []],
    'channel settings' => ['app.channels.settings', fn (SocialAccount $account) => [$account->id]],
    'calendar' => ['app.calendar', fn () => ['view' => 'week']],
    'channel queue' => ['app.channels.publish', fn (SocialAccount $account) => [$account->id]],
]);

test('a reconnect offers only the card being reconnected and updates it', function () {
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'x-1',
        'access_token' => 'old-token',
    ]);

    startSocialConnect($this->workspace, Platform::X, $account);
    fakeXLogin();

    $this->actingAs($this->user)->get(route('app.social.x.callback'))->assertRedirect();

    $this->get(route('app.social.connect.show', Platform::X))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reconnecting', true)
            ->where('identities.0.key', 'x:x-1')
            ->where('identities.0.locked', false)
        );

    finishSocialConnect(Platform::X)
        ->assertInertiaFlash('connectedChannel.accountId', $account->id)
        ->assertInertiaFlash('connectedChannel.created', false);

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('x-access-token');
});

test('the confirmation page shows each stop reason with a way back and a retry', function (string $failure, string $state) {
    startSocialConnect($this->workspace, Platform::X, null, '/settings/workspace/channels')->fail($failure);

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::X))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', $state)
            ->where('identities', [])
            ->where('backUrl', route('app.workspace.channels'))
            ->where('retryUrl', route('app.social.x.connect', ['return_to' => '/settings/workspace/channels']))
        );
})->with([
    'cancelled' => ['cancelled', 'cancelled'],
    'missing permission' => ['publish_permission_missing', 'missing_permission'],
    'network error' => ['error_connecting', 'error'],
    'taken' => ['network_taken', 'error'],
]);

test('the error state explains the reason', function () {
    startSocialConnect($this->workspace, Platform::Facebook)->fail('no_facebook_pages');

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::Facebook))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'error')
            ->where('reason', __('accounts.connect.errors.no_facebook_pages'))
        );
});

test('a retry after a failed reconnect starts the same reconnect again', function () {
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    startSocialConnect($this->workspace, Platform::X, $account)->fail('wrong_account');

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::X))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('reconnecting', true)
            ->where('retryUrl', route('app.social.x.connect', [
                'reconnect' => $account->id,
                'return_to' => '/settings/workspace/channels',
            ]))
        );
});

test('the confirmation page without any connection shows the expired state', function () {
    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::GoogleBusiness))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'expired')
            ->where('retryUrl', route('app.social.google-business.connect'))
            ->where('backUrl', route('app.workspace.channels'))
        );
});

test('the confirmation page does not exist for networks connected without a redirect', function (Platform $platform) {
    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', $platform))
        ->assertNotFound();
})->with([Platform::Telegram, Platform::LinkedInPage]);

test('a guest is sent to log in', function () {
    $this->get(route('app.social.connect.show', Platform::X))->assertRedirect(route('login'));
    $this->post(route('app.social.connect.finish', Platform::X))->assertRedirect(route('login'));
});

test('finishing a connection is a web form protected against forgery', function () {
    expect(Route::getRoutes()->getByName('app.social.connect.finish')->gatherMiddleware())->toContain('web');
});

test('a cancelled consent keeps where to return for the retry and connects nothing', function () {
    startSocialConnect($this->workspace, Platform::X, null, '/settings/workspace/channels');

    $this->actingAs($this->user)
        ->get(route('app.social.x.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('app.social.connect.show', Platform::X));

    $this->get(route('app.social.connect.show', Platform::X))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'cancelled')
            ->where('backUrl', route('app.workspace.channels'))
        );

    $this->assertDatabaseCount('social_accounts', 0);
});

test('several channels connect at once on any plan, since channels have no cap', function () {
    $this->user->account->update(['plan_id' => Plan::query()->where('slug', Slug::Socials)->value('id')]);

    startSocialConnect($this->workspace, Platform::Facebook)->offer([
        offeredFacebookPage('p1', 'One'),
        offeredFacebookPage('p2', 'Two'),
        offeredFacebookPage('p3', 'Three'),
    ]);

    $this->actingAs($this->user);

    assertFinishedOnChannel(finishSocialConnect(Platform::Facebook));

    expect($this->workspace->socialAccounts()->count())->toBe(3);
});

test('finishing a new connection dispatches social_account.connected with source', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Bus::fake([TrackSocialAccountConnected::class]);

    startSocialConnect($this->workspace, Platform::Facebook, null, route('app.calendar', ['view' => 'week'], false))->offer([
        offeredFacebookPage('p1', 'One'),
        offeredFacebookPage('p2', 'Two'),
    ]);

    assertFinishedOnChannel($this->actingAs($this->user)->post(route('app.social.connect.finish', Platform::Facebook), [
        'identities' => ['facebook:p1', 'facebook:p2'],
    ]));

    $accountIds = $this->workspace->socialAccounts()->pluck('id')->map(strval(...))->sort()->values()->all();

    Bus::assertDispatchedTimes(TrackSocialAccountConnected::class, 2);
    Bus::assertDispatched(TrackSocialAccountConnected::class, fn (TrackSocialAccountConnected $job): bool => in_array($job->socialAccountId, $accountIds, true)
        && $job->userId === (string) $this->user->id
        && $job->source === 'app.calendar');
});

test('reconnecting does not dispatch connected', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Bus::fake([TrackSocialAccountConnected::class]);

    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'x-1',
    ]);

    startSocialConnect($this->workspace, Platform::X, $account);
    fakeXLogin();

    $this->actingAs($this->user)->get(route('app.social.x.callback'))->assertRedirect();

    finishSocialConnect(Platform::X)->assertInertiaFlash('connectedChannel.created', false);

    Bus::assertNotDispatched(TrackSocialAccountConnected::class);
});

test('finishing a connection does not dispatch connected when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Bus::fake([TrackSocialAccountConnected::class]);

    startSocialConnect($this->workspace, Platform::Facebook)->offer([offeredFacebookPage('p1', 'One')]);

    assertFinishedOnChannel($this->actingAs($this->user)->post(route('app.social.connect.finish', Platform::Facebook), [
        'identities' => ['facebook:p1'],
    ]));

    Bus::assertNotDispatched(TrackSocialAccountConnected::class);
});

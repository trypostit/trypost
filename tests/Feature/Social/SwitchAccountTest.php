<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));

    collect(Platform::cases())->each(fn (Platform $platform) => config()->set("trypost.platforms.{$platform->value}.enabled", true));
});

/**
 * The network's authorize URL the start route redirects to, decoded.
 *
 * @param  array<string, mixed>  $query
 */
function switchAccountAuthorizeUrl(string $route, array $query = []): string
{
    $response = test()->actingAs(test()->user)->get(route($route, $query));

    $response->assertRedirect();

    return urldecode((string) $response->headers->get('Location'));
}

function offerSwitchAccountIdentity(Workspace $workspace, Platform $platform, ?SocialAccount $reconnect = null, ?string $returnTo = null): PendingConnection
{
    $pending = startSocialConnect($workspace, $platform, $reconnect, $returnTo);

    $pending->offer([
        PendingConnection::identity($platform, 'first-login', 'First login', 'first', null, 'profile', ['access_token' => 'first-token']),
    ]);

    return $pending;
}

test('switching account asks the network for its login or account chooser', function (string $route, string $switchParameter) {
    expect(switchAccountAuthorizeUrl($route, ['switch' => '1']))->toContain($switchParameter)
        ->and(switchAccountAuthorizeUrl($route))->not->toContain($switchParameter);
})->with([
    'google business' => ['app.social.google-business.connect', 'prompt=select_account consent'],
    'facebook' => ['app.social.facebook.connect', 'auth_type=rerequest,reauthenticate'],
    'instagram via facebook' => ['app.social.instagram-facebook.connect', 'auth_type=rerequest,reauthenticate'],
]);

test('a plain connect keeps the parameters it always sends', function (string $route, string $parameter) {
    expect(switchAccountAuthorizeUrl($route))->toContain($parameter)
        ->and(switchAccountAuthorizeUrl($route, ['switch' => '1']))->toContain($parameter);
})->with([
    'facebook re-asks declined permissions' => ['app.social.facebook.connect', 'auth_type=rerequest'],
    'instagram via facebook re-asks declined permissions' => ['app.social.instagram-facebook.connect', 'auth_type=rerequest'],
    'google business asks for consent' => ['app.social.google-business.connect', 'consent'],
    'youtube always shows the account chooser' => ['app.social.youtube.connect', 'prompt=select_account consent'],
    'tiktok always shows its authorization page' => ['app.social.tiktok.connect', 'disable_auto_auth=1'],
    'instagram always asks for the instagram login' => ['app.social.instagram.connect', 'force_reauth=true'],
]);

test('networks without a documented account chooser get no extra parameter', function (string $route) {
    $url = switchAccountAuthorizeUrl($route, ['switch' => '1']);

    expect($url)
        ->not->toContain('force_login')
        ->not->toContain('force_reauth')
        ->not->toContain('select_account')
        ->not->toContain('reauthenticate')
        ->not->toContain('switch=');
})->with([
    'x' => 'app.social.x.connect',
    'linkedin' => 'app.social.linkedin.connect',
    'pinterest' => 'app.social.pinterest.connect',
    'threads' => 'app.social.threads.connect',
    'discord' => 'app.social.discord.connect',
]);

test('any value other than the exact flag is ignored', function (mixed $flag) {
    $query = ['switch' => $flag];

    expect(switchAccountAuthorizeUrl('app.social.google-business.connect', $query))->not->toContain('select_account')
        ->and(switchAccountAuthorizeUrl('app.social.facebook.connect', $query))->not->toContain('reauthenticate')
        ->and(PendingConnection::current()?->isSwitchingAccount())->toBeFalse();
})->with([
    'true' => 'true',
    'yes' => 'yes',
    'zero' => '0',
    'two' => '2',
    'empty' => '',
    'array' => [['1']],
]);

test('restarting from switch account discards the identities of the first login', function () {
    $reconnect = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'platform_user_id' => 'first-login']);
    $returnTo = (string) parse_url(route('app.posts.index'), PHP_URL_PATH);

    offerSwitchAccountIdentity($this->workspace, Platform::X, $reconnect, $returnTo);

    expect(PendingConnection::current()->isReady())->toBeTrue();

    switchAccountAuthorizeUrl('app.social.x.connect', ['switch' => '1', 'reconnect' => $reconnect->id, 'return_to' => $returnTo]);

    $pending = PendingConnection::current();

    expect($pending->isReady())->toBeFalse()
        ->and($pending->identities())->toBe([])
        ->and($pending->failure())->toBeNull()
        ->and($pending->isSwitchingAccount())->toBeTrue()
        ->and($pending->reconnectId())->toBe($reconnect->id)
        ->and($pending->returnUrl())->toBe(route('app.posts.index'));
});

test('switching account on a form network returns to its empty form step', function (string $route, string $component, Platform $platform) {
    offerSwitchAccountIdentity($this->workspace, $platform);

    $this->actingAs($this->user)
        ->get(route($route, ['switch' => '1']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component));

    expect(PendingConnection::current()->isReady())->toBeFalse()
        ->and(PendingConnection::current()->isSwitchingAccount())->toBeTrue();
})->with([
    'bluesky' => ['app.social.bluesky.connect', 'accounts/BlueskyConnect', Platform::Bluesky],
    'mastodon' => ['app.social.mastodon.connect', 'accounts/MastodonConnect', Platform::Mastodon],
]);

test('mastodon forces a new login on the instance only when switching account', function (bool $switching) {
    fakePublicDns();

    Http::fake([
        'https://mastodon.social/api/v1/apps' => Http::response(['client_id' => 'client-id', 'client_secret' => 'client-secret']),
    ]);

    $this->actingAs($this->user)->get(route('app.social.mastodon.connect', $switching ? ['switch' => '1'] : []));

    $location = (string) $this->actingAs($this->user)
        ->post(route('app.social.mastodon.authorize'), ['instance' => 'https://mastodon.social'])
        ->headers->get('Location');

    expect(str_contains($location, 'force_login=true'))->toBe($switching);
})->with(['switching' => true, 'plain connect' => false]);

test('the confirmation page links switch account to the start route with the same context', function () {
    $reconnect = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'platform_user_id' => 'first-login']);
    $returnTo = (string) parse_url(route('app.posts.index'), PHP_URL_PATH);

    offerSwitchAccountIdentity($this->workspace, Platform::X, $reconnect, $returnTo);

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::X))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'select')
            ->where('switchUrl', route('app.social.x.connect', ['reconnect' => $reconnect->id, 'return_to' => $returnTo, 'switch' => '1'])));
});

test('switch account is offered only while there is an account to confirm', function () {
    startSocialConnect($this->workspace, Platform::X)->fail('cancelled');

    $this->actingAs($this->user)
        ->get(route('app.social.connect.show', Platform::X))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'cancelled')->where('switchUrl', null));
});

test('google connects ask only for their own scopes, never the ones granted to other features of the same app', function (string $route, string $scope) {
    $url = urldecode(switchAccountAuthorizeUrl($route));

    expect($url)->toContain($scope)
        ->not->toContain('include_granted_scopes')
        ->not->toContain('drive.file');
})->with([
    'youtube' => ['app.social.youtube.connect', 'https://www.googleapis.com/auth/youtube.upload'],
    'google business' => ['app.social.google-business.connect', 'https://www.googleapis.com/auth/business.manage'],
]);

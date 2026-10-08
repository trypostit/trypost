<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function waitForSwitchAccountTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 160; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    waitForWebFonts($page);
}

function switchAccountPollFor(mixed $page, string $condition, int $attempts = 160): bool
{
    return (bool) $page->script(<<<JS
        (async () => {
            for (let i = 0; i < {$attempts}; i++) {
                if ({$condition}) return true;
                await new Promise((r) => setTimeout(r, 50));
            }
            return false;
        })();
    JS);
}

function switchAccountAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

/**
 * Facebook's login dialog sends the browser straight back to the callback with
 * one page; every `with()` parameter the start route asks for is recorded.
 *
 * @param  array<int, array<string, string>>  $parameters
 */
function stubSwitchAccountFacebook(array &$parameters): void
{
    $login = new SocialiteUser;
    $login->map(['id' => 'fb-user', 'name' => 'Owner']);
    $login->setToken('fb-user-token');

    $stub = Mockery::mock();
    $stub->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => route('app.social.facebook.callback', ['code' => 'code-1']),
    ]));
    $stub->shouldReceive('user')->andReturn($login);
    $stub->shouldReceive('with')->andReturnUsing(function (array $with) use (&$parameters, $stub) {
        $parameters[] = $with;

        return $stub;
    });
    $stub->shouldIgnoreMissing($stub);

    Socialite::shouldReceive('driver')->with('facebook')->andReturn($stub);

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_manage_posts', 'status' => 'granted'],
        ]]),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []]),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [
            ['id' => 'page-1', 'name' => 'Bakery Downtown', 'picture' => ['data' => ['url' => null]], 'access_token' => 'token-1'],
        ]]),
        "{$graphApi}/*" => Http::response(['id' => 'fb-user', 'name' => 'Owner']),
    ]);
}

function switchAccountPath(string $route, mixed ...$parameters): string
{
    return (string) parse_url(route($route, ...$parameters), PHP_URL_PATH);
}

test('switch account shows how to use another account and back returns to the selection', function () {
    $this->actingAs(switchAccountAdmin());
    $parameters = [];
    stubSwitchAccountFacebook($parameters);

    $page = visit(route('app.social.facebook.connect', ['return_to' => switchAccountPath('app.posts.index')]))->resize(1280, 900);
    waitForSwitchAccountTestId($page, 'connect-switch-account');

    $page->assertSeeIn('@connect-switch-account-label', __('accounts.connect.switch.button'))
        ->assertMissing('@connect-header-label')
        ->click('@connect-switch-account');
    waitForSwitchAccountTestId($page, 'connect-switch-state');

    $page->assertSeeIn('@connect-switch-title', __('accounts.connect.switch.title'))
        ->assertSeeIn('@connect-switch-description', __('accounts.connect.switch.description', ['site' => 'Facebook', 'network' => 'Facebook']))
        ->assertSeeIn('@connect-switch-connect', __('accounts.connect.switch.connect', ['network' => 'Facebook']))
        ->assertVisible('@connect-header-logos')
        ->assertVisible('@connect-close')
        ->assertVisible('@connect-switch-back')
        ->assertMissing('@connect-switch-account')
        ->assertMissing('@connect-identities')
        ->assertMissing('@connect-finish');

    $page->click('@connect-switch-back');
    waitForSwitchAccountTestId($page, 'connect-identities');

    $page->assertVisible('@connect-finish')
        ->assertVisible('@connect-switch-account')
        ->assertMissing('@connect-switch-state')
        ->assertNoJavaScriptErrors();
});

test('connect restarts the login with the switch flag, the account chooser and the same return page', function () {
    $this->actingAs(switchAccountAdmin());
    $parameters = [];
    stubSwitchAccountFacebook($parameters);

    $returnTo = switchAccountPath('app.posts.index');
    $page = visit(route('app.social.facebook.connect', ['return_to' => $returnTo]))->resize(1280, 900);
    waitForSwitchAccountTestId($page, 'connect-switch-account');

    $page->click('@connect-switch-account');
    waitForSwitchAccountTestId($page, 'connect-switch-connect');

    $href = (string) $page->script('document.querySelector(\'[data-testid="connect-switch-connect"]\').href');

    expect($href)->toBe(route('app.social.facebook.connect', ['return_to' => $returnTo, 'switch' => '1']))
        ->and($parameters)->toBe([]);

    $page->click('@connect-switch-connect');

    expect(switchAccountPollFor($page, '!document.querySelector(\'[data-testid="connect-switch-state"]\') && document.querySelector(\'[data-testid="connect-identities"]\')'))->toBeTrue()
        ->and($parameters)->toBe([['auth_type' => 'rerequest,reauthenticate']]);

    $page->assertSee('Bakery Downtown')
        ->assertAttribute('@connect-close', 'href', route('app.posts.index'))
        ->assertNoJavaScriptErrors();
});

test('switch account on bluesky returns to its form with empty fields', function () {
    $this->actingAs(switchAccountAdmin());
    $service = config('trypost.platforms.bluesky.default_service');

    Http::fake([
        "{$service}/xrpc/com.atproto.server.createSession" => Http::response([
            'emailConfirmed' => true,
            'did' => 'did:plc:first',
            'handle' => 'first.bsky.social',
            'accessJwt' => 'access-token',
            'refreshJwt' => 'refresh-token',
        ]),
        "{$service}/xrpc/com.atproto.server.getSession" => Http::response(['did' => 'did:plc:first', 'emailConfirmed' => true]),
        "{$service}/xrpc/app.bsky.actor.getProfile*" => Http::response([
            'did' => 'did:plc:first',
            'handle' => 'first.bsky.social',
            'displayName' => 'First Bluesky',
        ]),
    ]);

    $page = visit(route('app.social.bluesky.connect'))->resize(1280, 900);
    waitForSwitchAccountTestId($page, 'bluesky-identifier');

    $page->type('@bluesky-identifier', 'first.bsky.social')
        ->type('@bluesky-password', 'xxxx-xxxx-xxxx-xxxx')
        ->click('@bluesky-submit');
    waitForSwitchAccountTestId($page, 'connect-switch-account');

    $page->assertSee('First Bluesky')->click('@connect-switch-account');

    expect(switchAccountPollFor($page, 'document.querySelector(\'[data-testid="bluesky-identifier"]\')'))->toBeTrue();
    waitForSwitchAccountTestId($page, 'bluesky-identifier');

    expect($page->script('[document.querySelector(\'[data-testid="bluesky-identifier"]\').value, document.querySelector(\'[data-testid="bluesky-password"]\').value]'))->toBe(['', ''])
        ->and($page->script('new URL(window.location.href).searchParams.get("switch")'))->toBe('1');

    $page->assertMissing('@connect-switch-state')->assertNoJavaScriptErrors();
});

test('on a phone switch account shows a short label next to the logos', function () {
    $this->actingAs(switchAccountAdmin());
    $parameters = [];
    stubSwitchAccountFacebook($parameters);

    $page = visit(route('app.social.facebook.connect'))->resize(390, 640);
    waitForSwitchAccountTestId($page, 'connect-switch-account');

    $failures = $page->script(<<<'JS'
        (() => {
            const button = document.querySelector('[data-testid="connect-switch-account"]').getBoundingClientRect();
            const full = document.querySelector('[data-testid="connect-switch-account-label"]').getBoundingClientRect();
            const logos = document.querySelector('[data-testid="connect-header-logos"]').getBoundingClientRect();
            const failures = [];

            if (full.width !== 0) failures.push('full label visible');
            if (button.left < 0 || button.right > logos.left) failures.push('overlaps the logos');
            if (document.documentElement.scrollWidth > window.innerWidth) failures.push('horizontal scroll');

            return failures;
        })()
    JS);

    expect($failures)->toBe([]);

    $page->click('@connect-switch-account');
    waitForSwitchAccountTestId($page, 'connect-switch-state');

    expect($page->script(<<<'JS'
        (() => {
            const footer = document.querySelector('[data-testid="connect-footer"]').getBoundingClientRect();
            const cta = document.querySelector('[data-testid="connect-switch-connect"]').getBoundingClientRect();

            return footer.bottom <= window.innerHeight + 1 && cta.right <= window.innerWidth;
        })()
    JS))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

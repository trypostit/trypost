<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function waitForConnectConfirmTestId(mixed $page, string $testId): void
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

function connectConfirmPollFor(mixed $page, string $condition, int $attempts = 160): bool
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

function connectConfirmAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

/**
 * The network's consent screen sends the browser straight back to the callback,
 * with the query a real network would add, and the login it returns.
 *
 * @param  array<string, string>  $callbackQuery
 */
function stubConnectConfirmProvider(string $driver, string $callbackRoute, array $callbackQuery, ?SocialiteUser $login, string $fragment = ''): void
{
    $stub = Mockery::mock();
    $stub->shouldReceive('redirect')->andReturn(Mockery::mock([
        'getTargetUrl' => route($callbackRoute, $callbackQuery).$fragment,
    ]));

    if ($login === null) {
        $stub->shouldReceive('user')->andThrow(new RuntimeException('Token exchange failed'));
    } else {
        $stub->shouldReceive('user')->andReturn($login);
    }

    $stub->shouldIgnoreMissing($stub);

    Socialite::shouldReceive('driver')->with($driver)->andReturn($stub);
}

/**
 * @param  array<int, string>  $scopes
 */
function connectConfirmXLogin(string $id = 'x-brand', array $scopes = ['tweet.read', 'tweet.write', 'users.read']): SocialiteUser
{
    $login = new SocialiteUser;
    $login->map(['id' => $id, 'nickname' => 'brand', 'name' => 'Brand on X', 'avatar' => null]);
    $login->setToken('x-access-token')->setRefreshToken('x-refresh-token')->setExpiresIn(7200)->setApprovedScopes($scopes);

    return $login;
}

function connectConfirmFacebookPages(): void
{
    $login = new SocialiteUser;
    $login->map(['id' => 'fb-user', 'name' => 'Owner']);
    $login->setToken('fb-user-token');

    stubConnectConfirmProvider('facebook', 'app.social.facebook.callback', ['code' => 'code-1'], $login);

    $graphApi = config('trypost.platforms.facebook.graph_api');

    Http::fake([
        "{$graphApi}/me/permissions*" => Http::response(['data' => [
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_manage_posts', 'status' => 'granted'],
        ]]),
        "{$graphApi}/me/businesses*" => Http::response(['data' => []]),
        "{$graphApi}/me/accounts*" => Http::response(['data' => [
            ['id' => 'page-1', 'name' => 'Bakery Downtown', 'picture' => ['data' => ['url' => null]], 'access_token' => 'token-1'],
            ['id' => 'page-2', 'name' => 'Bakery Uptown', 'picture' => ['data' => ['url' => null]], 'access_token' => 'token-2'],
            ['id' => 'page-3', 'name' => 'Bakery Airport', 'picture' => ['data' => ['url' => null]], 'access_token' => 'token-3'],
        ]]),
        "{$graphApi}/*" => Http::response(['id' => 'fb-user', 'name' => 'Owner']),
    ]);
}

function connectConfirmPath(string $route, mixed ...$parameters): string
{
    return (string) parse_url(route($route, ...$parameters), PHP_URL_PATH);
}

test('several pages: pick some, select all, and finish on the first connected channel', function () {
    $user = connectConfirmAdmin();
    $this->actingAs($user);
    connectConfirmFacebookPages();

    $page = visit(route('app.social.facebook.connect', ['return_to' => connectConfirmPath('app.workspace.channels')]))->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'connect-identities');

    $page->assertSeeIn('@connect-title', __('accounts.connect.title_select'))
        ->assertSeeIn('@connect-selected-count', __('accounts.connect.selected', ['count' => 0]))
        ->assertSeeIn('@connect-switch-account-label', __('accounts.connect.switch.button'))
        ->assertSee('Bakery Uptown')
        ->assertSee(__('accounts.connect.types.page'));

    expect($page->script('document.querySelector(\'[data-testid="connect-finish"]\').disabled'))->toBeTrue();

    $page->click('@connect-select-all');
    $page->assertSeeIn('@connect-selected-count', __('accounts.connect.selected', ['count' => 3]));
    $page->click('@connect-select-all');
    $page->assertSeeIn('@connect-selected-count', __('accounts.connect.selected', ['count' => 0]));

    $page->click('[data-testid="connect-identity-facebook:page-1"]')
        ->click('[data-testid="connect-identity-facebook:page-3"]');
    $page->assertSeeIn('@connect-selected-count', __('accounts.connect.selected', ['count' => 2]));

    expect($page->script('document.querySelector(\'[data-testid="connect-identity-facebook:page-1"]\').dataset.selected'))->toBe('true')
        ->and($page->script('document.querySelector(\'[data-testid="connect-finish"]\').disabled'))->toBeFalse();

    $page->click('@connect-finish');

    waitForConnectConfirmTestId($page, 'goal-flow');

    expect($user->currentWorkspace->socialAccounts()->pluck('platform_user_id')->sort()->values()->all())->toBe(['page-1', 'page-3']);

    $page->assertVisible('@goal-flow')->assertNoJavaScriptErrors();
});

test('pages already connected stay in place, locked, uncounted and skipped by select all', function () {
    $user = connectConfirmAdmin();
    $connected = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform_user_id' => 'page-2',
        'access_token' => 'old-token',
    ]);
    $this->actingAs($user);
    connectConfirmFacebookPages();

    $page = visit(route('app.social.facebook.connect', ['return_to' => connectConfirmPath('app.workspace.channels')]))->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'connect-identities');

    expect($page->script(<<<'JS'
        Array.from(document.querySelectorAll('[data-testid="connect-identities"] > li > [data-testid]')).map((card) => [card.dataset.testid, card.dataset.locked])
    JS))->toBe([
        ['connect-identity-facebook:page-1', 'false'],
        ['connect-identity-facebook:page-2', 'true'],
        ['connect-identity-facebook:page-3', 'false'],
    ]);

    $page->assertSeeIn('[data-testid="connect-identity-connected-facebook:page-2"]', __('accounts.connect.connected'))
        ->assertMissing('[data-testid="connect-identity-checkbox-facebook:page-2"]')
        ->assertSeeIn('@connect-selected-count', __('accounts.connect.selected', ['count' => 0]))
        ->assertMissing('@connect-all-connected');

    expect($page->script('document.querySelector(\'[data-testid="connect-finish"]\').disabled'))->toBeTrue();

    $page->click('[data-testid="connect-identity-facebook:page-2"]');
    $page->assertSeeIn('@connect-selected-count', __('accounts.connect.selected', ['count' => 0]));

    $page->click('@connect-select-all');
    $page->assertSeeIn('@connect-selected-count', __('accounts.connect.selected', ['count' => 2]));

    expect($page->script('document.querySelector(\'[data-testid="connect-select-all"]\').dataset.state'))->toBe('checked')
        ->and($page->script('document.querySelector(\'[data-testid="connect-identity-facebook:page-2"]\').dataset.selected'))->toBe('false');

    $page->click('@connect-finish');

    waitForConnectConfirmTestId($page, 'goal-flow');

    expect($user->currentWorkspace->socialAccounts()->pluck('platform_user_id')->sort()->values()->all())->toBe(['page-1', 'page-2', 'page-3'])
        ->and($connected->fresh()->access_token)->toBe('old-token');

    $page->assertNoJavaScriptErrors();
});

test('when every page is already connected the list is locked and says so', function () {
    $user = connectConfirmAdmin();
    foreach (['page-1', 'page-2', 'page-3'] as $pageId) {
        SocialAccount::factory()->facebook()->create([
            'workspace_id' => $user->current_workspace_id,
            'platform_user_id' => $pageId,
        ]);
    }
    $this->actingAs($user);
    connectConfirmFacebookPages();

    $page = visit(route('app.social.facebook.connect'))->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'connect-all-connected');

    $page->assertSeeIn('@connect-all-connected', __('accounts.connect.all_connected', ['network' => 'Facebook']))
        ->assertSeeIn('@connect-selected-count', __('accounts.connect.selected', ['count' => 0]));

    expect($page->script(<<<'JS'
        (() => ({
            checkboxes: document.querySelectorAll('[data-testid^="connect-identity-checkbox-"]').length,
            connected: document.querySelectorAll('[data-testid^="connect-identity-connected-"]').length,
            finishDisabled: document.querySelector('[data-testid="connect-finish"]').disabled,
            selectAllDisabled: document.querySelector('[data-testid="connect-select-all"]').disabled,
        }))()
    JS))->toBe([
        'checkboxes' => 0,
        'connected' => 3,
        'finishDisabled' => true,
        'selectAllDisabled' => true,
    ]);

    $page->assertNoJavaScriptErrors();
});

test('a cancelled consent shows the cancelled state with try again and back', function () {
    $user = connectConfirmAdmin();
    $this->actingAs($user);
    stubConnectConfirmProvider('x', 'app.social.x.callback', ['error' => 'access_denied'], connectConfirmXLogin());

    $postsPath = connectConfirmPath('app.posts.index');
    $page = visit(route('app.social.x.connect', ['return_to' => $postsPath]))->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'connect-state-cancelled');

    $page->assertSee(__('accounts.connect.states.cancelled.title'))
        ->assertSeeIn('@connect-retry', __('accounts.connect.actions.try_again'))
        ->assertSeeIn('@connect-back', __('accounts.connect.actions.back'))
        ->assertMissing('@connect-finish');

    expect($page->script('new URL(document.querySelector(\'[data-testid="connect-retry"]\').href).pathname'))->toBe(connectConfirmPath('app.social.x.connect'));

    $page->click('@connect-back');

    expect(connectConfirmPollFor($page, "window.location.pathname === '{$postsPath}'"))->toBeTrue();
    expect(SocialAccount::query()->count())->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('the close button leaves without connecting, back to where the connection started', function () {
    $this->actingAs(connectConfirmAdmin());
    connectConfirmFacebookPages();

    $postsPath = connectConfirmPath('app.posts.index');
    $page = visit(route('app.social.facebook.connect', ['return_to' => $postsPath]))->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'connect-identities');

    $page->click('@connect-close');

    expect(connectConfirmPollFor($page, "window.location.pathname === '{$postsPath}'"))->toBeTrue()
        ->and(SocialAccount::query()->count())->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('need help opens the connection guide, the docs and what to do when an account is missing', function () {
    $this->actingAs(connectConfirmAdmin());
    connectConfirmFacebookPages();

    $page = visit(route('app.social.facebook.connect'))->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'connect-help');

    $page->click('@connect-help');
    waitForConnectConfirmTestId($page, 'connect-help-guide');

    expect($page->script(<<<'JS'
        (() => ({
            guide: document.querySelector('[data-testid="connect-help-guide"]').href,
            docs: document.querySelector('[data-testid="connect-help-docs"]').href,
            target: document.querySelector('[data-testid="connect-help-guide"]').target,
        }))()
    JS))->toBe([
        'guide' => 'https://docs.trypost.it/platforms/facebook',
        'docs' => 'https://docs.trypost.it/',
        'target' => '_blank',
    ]);

    $page->assertSeeIn('@connect-help-missing', __('accounts.connect.help.missing'))->assertNoJavaScriptErrors();
});

test('on a phone the cards fill the width and the footer stays in view', function () {
    $this->actingAs(connectConfirmAdmin());
    connectConfirmFacebookPages();

    $page = visit(route('app.social.facebook.connect'))->resize(390, 640);
    waitForConnectConfirmTestId($page, 'connect-identities');

    expect($page->script(<<<'JS'
        (() => {
            const footer = document.querySelector('[data-testid="connect-footer"]').getBoundingClientRect();
            const card = document.querySelector('[data-testid="connect-identity-facebook:page-1"]').getBoundingClientRect();
            const header = document.querySelector('[data-testid="connect-switch-account-label"]');

            return {
                footerInView: footer.bottom <= window.innerHeight + 1 && footer.top >= 0,
                cardFillsWidth: card.width >= window.innerWidth - 32 - 1,
                horizontalScroll: document.documentElement.scrollWidth > window.innerWidth,
                headerLabelHidden: header.getBoundingClientRect().width === 0,
            };
        })()
    JS))->toBe([
        'footerInView' => true,
        'cardFillsWidth' => true,
        'horizontalScroll' => false,
        'headerLabelHidden' => true,
    ]);

    $page->assertNoJavaScriptErrors();
});

test('the bluesky and mastodon steps use the same page with the TryPost mark next to the network logo', function (string $route, string $platform, string $title, string $hint) {
    $this->actingAs(connectConfirmAdmin());

    $page = visit(route($route))->resize(1280, 900);
    waitForConnectConfirmTestId($page, $hint);

    expect($page->script(<<<'JS'
        (() => {
            const logos = document.querySelector('[data-testid="connect-header-logos"]');
            return {
                mark: logos.querySelector('[data-testid="app-logo"] svg') !== null,
                network: logos.querySelector('img')?.getAttribute('src') ?? null,
                title: document.querySelector('[data-testid="connect-title"]').textContent.trim(),
            };
        })()
    JS))->toMatchArray([
        'mark' => true,
        'network' => "/images/accounts/{$platform}.png",
        'title' => __($title),
    ]);

    $page->assertVisible('@connect-close')->assertNoJavaScriptErrors();
})->with([
    'bluesky' => ['app.social.bluesky.connect', 'bluesky', 'accounts.bluesky.title', 'bluesky-app-password-hint'],
    'mastodon' => ['app.social.mastodon.connect', 'mastodon', 'accounts.mastodon.title', 'mastodon-instance-hint'],
]);

test('a bluesky connection started without a return page finishes on its channel page', function () {
    $user = connectConfirmAdmin();
    $this->actingAs($user);
    $service = config('trypost.platforms.bluesky.default_service');
    Http::fake([
        "{$service}/xrpc/com.atproto.server.createSession" => Http::response([
            'did' => 'did:plc:confirm', 'handle' => 'confirm.bsky.social', 'accessJwt' => 'access', 'refreshJwt' => 'refresh',
        ]),
        "{$service}/xrpc/app.bsky.actor.getProfile*" => Http::response(['displayName' => 'Confirm Bluesky']),
    ]);

    $page = visit(route('app.social.bluesky.connect'))->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'bluesky-identifier');
    $page->type('@bluesky-identifier', 'confirm.bsky.social')
        ->type('@bluesky-password', 'xxxx-xxxx-xxxx-xxxx')
        ->click('@bluesky-submit');
    waitForConnectConfirmTestId($page, 'connect-finish');

    $page->assertSee('Confirm Bluesky')->click('@connect-finish');

    waitForConnectConfirmTestId($page, 'goal-flow');

    $channel = $user->currentWorkspace->socialAccounts()->sole();

    expect(connectConfirmPollFor($page, "window.location.pathname === '".connectConfirmPath('app.channels.publish', $channel)."'"))->toBeTrue()
        ->and($channel->platform_user_id)->toBe('did:plc:confirm');

    $page->assertNoJavaScriptErrors();
});

test('the fragment a network appends to its redirect is cleared from the address bar', function () {
    $this->actingAs(connectConfirmAdmin());

    $page = visit(route('app.social.connect.show', Platform::Threads).'#_')->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'connect-state-expired');

    expect(connectConfirmPollFor($page, "window.location.hash === ''"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the bluesky and mastodon forms use the standard field layout with hints under the inputs', function () {
    $this->actingAs(connectConfirmAdmin());

    $page = visit(route('app.social.bluesky.connect'))->resize(600, 700);
    waitForConnectConfirmTestId($page, 'bluesky-app-password-hint');

    $page->assertSee(__('accounts.bluesky.email'))
        ->assertVisible('@bluesky-app-password-hint')
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelectorAll('[role=\"alert\"], [data-slot=\"alert\"]').length"))->toBe(0);

    $page = visit(route('app.social.mastodon.connect'))->resize(600, 700);
    waitForConnectConfirmTestId($page, 'mastodon-instance-hint');

    $page->assertSee(__('accounts.mastodon.instance_hint'))
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelectorAll('[role=\"alert\"], [data-slot=\"alert\"]').length"))->toBe(0);
});

test('the fragment a network appends to the callback is gone once the confirmation page shows', function (string $fragment) {
    $this->actingAs(connectConfirmAdmin());
    stubConnectConfirmProvider('x', 'app.social.x.callback', ['code' => 'code-1'], connectConfirmXLogin(), $fragment);

    $page = visit(route('app.social.x.connect'))->resize(1280, 900);
    waitForConnectConfirmTestId($page, 'connect-finish');

    expect(connectConfirmPollFor($page, "window.location.hash === '' && !window.location.href.includes('#')"))->toBeTrue();
    expect($page->script('window.history.state?.page?.url ?? ""'))->not->toContain('#');
    $page->assertNoJavaScriptErrors();
})->with(['#_=_']);

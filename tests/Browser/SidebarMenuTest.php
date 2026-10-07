<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\User\Locale;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser assertions
 * do not auto-wait on SPA paint.
 */
function waitForSidebarTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    waitForWebFonts($page);
}

test('account owners see settings, channels and plans and billing in the sidebar menu', function () {
    config(['trypost.self_hosted' => false]);

    $plan = Plan::query()->where('slug', Slug::Socials)->firstOrFail();
    $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $user->account->update(['plan_id' => $plan->id]);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id]);

    subscribeAccount($user->account);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));

    waitForSidebarTestId($page, 'sidebar-workspace-menu');

    $page->assertVisible('@sidebar-workspace-menu')
        ->click('@sidebar-workspace-menu');

    waitForSidebarTestId($page, 'logout-button');

    $page->assertMissing('@sidebar-menu-email')
        ->assertVisible('@sidebar-menu-avatar')
        ->assertSeeIn('@sidebar-menu-name', 'Ada Lovelace')
        ->assertSeeIn('@sidebar-menu-plan', "{$plan->name} · 2 channels")
        ->assertVisible('@sidebar-menu-settings')
        ->assertVisible('@sidebar-menu-billing')
        ->assertVisible('@sidebar-menu-channels')
        ->assertVisible('@sidebar-menu-manage-team')
        ->assertVisible('@logout-button');

    expect($page->script('document.querySelector(\'[data-testid="sidebar-menu-settings"]\').getAttribute("href")'))
        ->toBe(route('app.profile.edit', absolute: false))
        ->and($page->script('document.querySelector(\'[data-testid="sidebar-menu-channels"]\').getAttribute("href")'))
        ->toBe(route('app.workspace.channels', absolute: false))
        ->and($page->script('document.querySelector(\'[data-testid="sidebar-menu-billing"]\').getAttribute("href")'))
        ->toBe(route('app.billing.index', absolute: false));

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid^="sidebar-menu-section-"], [data-testid="sidebar-workspaces-trigger"], [data-testid="sidebar-menu-settings"], [data-testid="sidebar-theme-trigger"], [data-testid="sidebar-support-trigger"], [data-testid="logout-button"]')]
            .map((element) => element.dataset.testid)
    JS))->toBe([
        'sidebar-menu-section-workspace',
        'sidebar-workspaces-trigger',
        'sidebar-menu-settings',
        'sidebar-menu-section-preferences',
        'sidebar-theme-trigger',
        'sidebar-support-trigger',
        'logout-button',
    ]);

    waitForSidebarTestId($page, 'sidebar-menu-manage-team');
    $page->click('@sidebar-menu-manage-team');

    $membersPath = route('app.members', absolute: false);
    $page->script("(async () => { for (let i = 0; i < 150; i++) { if (location.pathname === '{$membersPath}') return; await new Promise((r) => setTimeout(r, 50)); } })();");

    $page->assertPathIs($membersPath)
        ->assertNoJavaScriptErrors();
});

test('account billing is hidden in the sidebar menu when self-hosted', function () {
    config(['trypost.self_hosted' => true]);

    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::query()->where('slug', Slug::Socials)->value('id')]);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));

    waitForSidebarTestId($page, 'sidebar-workspace-menu');

    $page->assertVisible('@sidebar-workspace-menu')
        ->click('@sidebar-workspace-menu');

    waitForSidebarTestId($page, 'logout-button');

    $page->assertVisible('@sidebar-menu-settings')
        ->assertMissing('@sidebar-menu-billing')
        ->assertVisible('@sidebar-menu-channels');

    expect(trim((string) $page->script('document.querySelector(\'[data-testid="sidebar-menu-plan"]\').textContent')))
        ->toBe('0 channels');
});

test('workspace members do not see channels or plans and billing in the sidebar menu', function () {
    config(['trypost.self_hosted' => false]);

    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        attachMember: true,
        setMemberCurrent: true,
    );

    $workspace->members()->updateExistingPivot($member->id, membershipPivot('member'));

    subscribeAccount($owner->account);

    $this->actingAs($member->fresh());

    $page = visit(route('app.calendar'));

    waitForSidebarTestId($page, 'sidebar-workspace-menu');

    $page->assertVisible('@sidebar-workspace-menu')
        ->click('@sidebar-workspace-menu');

    waitForSidebarTestId($page, 'logout-button');

    $page->assertVisible('@sidebar-menu-settings')
        ->assertMissing('@sidebar-menu-billing')
        ->assertMissing('@sidebar-menu-channels')
        ->assertMissing('@sidebar-menu-manage-team')
        ->assertVisible('@logout-button');
});

test('the sidebar new button and its menu use the brand colors', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    subscribeAccount($user->account);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));

    waitForSidebarTestId($page, 'sidebar-new');

    $colors = $page->script(<<<'JS'
        (() => {
            const style = getComputedStyle(document.querySelector('[data-testid="sidebar-new"]'));
            const icon = document.querySelector('[data-testid="sidebar-new"] svg');
            return [style.backgroundColor, style.color, style.borderRadius, icon ? getComputedStyle(icon).display : null];
        })()
    JS);

    expect($colors)->toBe(['rgb(109, 40, 217)', 'rgb(255, 255, 255)', '3.35544e+07px', 'none']);

    $page->click('@sidebar-new');
    waitForSidebarTestId($page, 'sidebar-new-idea');

    $menuColors = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                const post = document.querySelector('[data-testid="sidebar-new-post"] span');
                const idea = document.querySelector('[data-testid="sidebar-new-idea"] span');
                if (post && idea) {
                    return [getComputedStyle(post).backgroundColor, getComputedStyle(idea).backgroundColor];
                }
                await new Promise((r) => setTimeout(r, 50));
            }
            return null;
        })()
    JS);

    expect($menuColors)->toBe(['rgb(221, 214, 254)', 'rgb(237, 233, 254)']);

    $page->assertNoJavaScriptErrors();
});

test('workspaces without a logo use the brand colors in the workspace menu', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
        'name' => 'Acme',
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    subscribeAccount($user->account);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));

    waitForSidebarTestId($page, 'sidebar-workspace-menu');

    $colors = $page->script(<<<JS
        (async () => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));
            document.querySelector('[data-testid="sidebar-workspace-menu"]').click();
            await wait(400);
            const trigger = document.querySelector('[data-testid="sidebar-workspaces-trigger"]');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
            for (let i = 0; i < 60; i++) {
                const fallback = document.querySelector('[data-testid="sidebar-workspace-{$workspace->id}"] [data-slot="avatar"] > div');
                if (fallback) {
                    const style = getComputedStyle(fallback);
                    return [style.backgroundColor, style.color];
                }
                await wait(50);
            }
            return null;
        })()
    JS);

    expect($colors)->toBe(['rgb(237, 233, 254)', 'rgb(109, 40, 217)']);

    $page->assertNoJavaScriptErrors();
});

test('the main sidebar navigation is ordered and the logo is the vector mark with the wordmark', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForSidebarTestId($page, 'sidebar-logo');

    $paths = array_map(
        fn (string $url): string => (string) parse_url($url, PHP_URL_PATH),
        [route('app.create.ideas.index'), route('app.posts.index'), route('app.insights'), route('app.repurposes.index')],
    );

    expect($page->script(<<<'JS'
        Array.from(document.querySelectorAll('[data-testid^="nav-"]')).map((link) => new URL(link.href).pathname)
    JS))->toBe($paths);

    expect($page->script(<<<'JS'
        (() => {
            const logo = document.querySelector('[data-testid="sidebar-logo"]');
            const visible = Array.from(logo.querySelectorAll('[data-testid="app-logo"]')).filter((element) => element.getBoundingClientRect().width > 0);
            return {
                images: logo.querySelectorAll('img').length,
                visible: visible.length,
                text: visible[0]?.textContent.trim(),
                mark: visible[0]?.querySelector('svg') !== null,
            };
        })()
    JS))->toMatchArray([
        'images' => 0,
        'visible' => 1,
        'text' => 'trypost.it',
        'mark' => true,
    ])
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"sidebar-logo\"] [data-testid=\"app-logo\"]')).fontFamily"))->toContain('Roboto Slab');

    $page->assertNoJavaScriptErrors();
});

test('the sidebar menu opens right to left for a right-to-left language', function () {
    $user = User::factory()->create(['locale' => Locale::Arabic]);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.calendar'));
    waitForSidebarTestId($page, 'sidebar-workspace-menu');

    $page->click('@sidebar-workspace-menu');
    waitForSidebarTestId($page, 'logout-button');

    expect($page->script('document.querySelector(\'[data-testid="logout-button"]\').closest(\'[role="menu"]\').getAttribute("dir")'))
        ->toBe('rtl');

    $page->assertNoJavaScriptErrors();
});

function waitForSidebarState(mixed $page, string $state): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (document.querySelector('[data-slot="sidebar"][data-state]')?.dataset.state === '{$state}') return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('the sidebar footer toggle collapses and expands the sidebar and the state survives a reload', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $layout = <<<'JS'
        (() => {
            const toggle = document.querySelector('[data-testid="sidebar-footer-toggle"]').getBoundingClientRect();
            const avatar = document.querySelector('[data-testid="sidebar-workspace-menu"]').getBoundingClientRect();
            return {
                state: document.querySelector('[data-slot="sidebar"][data-state]')?.dataset.state,
                above: toggle.bottom <= avatar.top + 1,
                beside: toggle.left >= avatar.right - 1,
                label: document.querySelector('[data-testid="sidebar-footer-toggle"]').getAttribute('aria-label'),
            };
        })()
    JS;

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForSidebarTestId($page, 'sidebar-footer-toggle');

    expect($page->script($layout))->toMatchArray(['state' => 'expanded', 'beside' => true, 'label' => 'Collapse sidebar']);

    $page->click('@sidebar-footer-toggle');
    waitForSidebarState($page, 'collapsed');

    expect($page->script($layout))->toMatchArray(['state' => 'collapsed', 'above' => true, 'label' => 'Expand sidebar']);

    $page->script('location.reload()');
    waitForSidebarTestId($page, 'sidebar-footer-toggle');
    waitForSidebarState($page, 'collapsed');

    expect($page->script($layout))->toMatchArray(['state' => 'collapsed', 'above' => true]);

    $page->click('@sidebar-footer-toggle');
    waitForSidebarState($page, 'expanded');

    expect($page->script($layout))->toMatchArray(['state' => 'expanded', 'beside' => true]);

    $page->assertNoJavaScriptErrors();
});

test('the sidebar auto-collapses below 1024px, can be expanded there, and restores the saved state above it', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $state = "document.querySelector('[data-slot=\"sidebar\"][data-state]')?.dataset.state";

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForSidebarTestId($page, 'sidebar-footer-toggle');
    waitForSidebarState($page, 'expanded');

    $page->resize(900, 900);
    waitForSidebarState($page, 'collapsed');
    expect($page->script($state))->toBe('collapsed');

    $page->click('@sidebar-footer-toggle');
    waitForSidebarState($page, 'expanded');
    expect($page->script($state))->toBe('expanded')
        ->and($page->script('document.cookie.includes("sidebar_state")'))->toBeFalse();

    $page->resize(700, 900);
    waitForSidebarTestId($page, 'app-sidebar-trigger');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100 && document.querySelector('[data-testid="sidebar-footer-toggle"]'); i++) {
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
    expect($page->script("document.querySelector('[data-testid=\"sidebar-footer-toggle\"]')"))->toBeNull();

    $page->resize(1280, 900);
    waitForSidebarState($page, 'expanded');
    expect($page->script($state))->toBe('expanded');

    $page->assertNoJavaScriptErrors();
});

test('on a phone a global bar with the menu button and logo sits above the content card on every page', function (string $routeName) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    subscribeAccount($user->account);

    $this->actingAs($user);

    $page = visit(route($routeName))->resize(390, 844);
    waitForSidebarTestId($page, 'app-mobile-bar');

    $layout = <<<'JS'
        (() => {
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const bar = rect('app-mobile-bar');
            const card = document.querySelector('[data-testid="app-layout-scroller"]').parentElement;

            return {
                barVisible: bar.height > 0,
                triggerInBar: rect('app-sidebar-trigger').bottom <= bar.bottom,
                logoInBar: rect('app-mobile-logo').bottom <= bar.bottom,
                cardBelowBar: card.getBoundingClientRect().top >= bar.bottom,
                cardRounded: parseFloat(getComputedStyle(card).borderTopLeftRadius) > 0,
            };
        })()
    JS;

    expect($page->script($layout))->toBe([
        'barVisible' => true,
        'triggerInBar' => true,
        'logoInBar' => true,
        'cardBelowBar' => true,
        'cardRounded' => true,
    ]);

    $page->click('@app-sidebar-trigger');
    $mobileSidebar = '(document.querySelector(\'[data-mobile="true"]\')?.getBoundingClientRect().width ?? 0) > 0';
    $page->script("(async () => { for (let attempt = 0; attempt < 100; attempt++) { if ({$mobileSidebar}) return; await new Promise((resolve) => setTimeout(resolve, 50)); } })()");
    expect($page->script($mobileSidebar))->toBeTrue();

    $page->resize(1280, 900);
    expect($page->script('document.querySelector(\'[data-testid="app-mobile-bar"]\').getBoundingClientRect().height'))->toBe(0);

    $page->assertNoJavaScriptErrors();
})->with(['app.posts.index']);

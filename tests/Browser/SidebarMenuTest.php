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
}

test('account owners see settings, channels and plans and billing in the sidebar menu', function () {
    config(['trypost.self_hosted' => false]);

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

    waitForSidebarTestId($page, 'sidebar-workspace-menu');

    $page->assertVisible('@sidebar-workspace-menu')
        ->click('@sidebar-workspace-menu');

    waitForSidebarTestId($page, 'logout-button');

    $page->assertVisible('@sidebar-menu-settings')
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
});

test('the sidebar menu header shows the user, plan and channel count', function () {
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
    $page->click('@sidebar-workspace-menu');
    waitForSidebarTestId($page, 'sidebar-menu-plan');

    $page->assertMissing('@sidebar-menu-email')
        ->assertVisible('@sidebar-menu-avatar')
        ->assertSeeIn('@sidebar-menu-name', 'Ada Lovelace')
        ->assertSeeIn('@sidebar-menu-plan', "{$plan->name} · 2 channels")
        ->assertVisible('@sidebar-menu-manage-team')
        ->assertNoJavaScriptErrors();
});

test('manage team in the sidebar menu opens the members page', function () {
    config(['trypost.self_hosted' => false]);

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

    waitForSidebarTestId($page, 'sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
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

test('workspace admins see channels but not plans and billing', function () {
    config(['trypost.self_hosted' => false]);

    [
        'owner' => $owner,
        'member' => $admin,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        attachMember: true,
        setMemberCurrent: true,
    );

    $workspace->members()->updateExistingPivot($admin->id, membershipPivot('admin'));

    subscribeAccount($owner->account);

    $this->actingAs($admin->fresh());

    $page = visit(route('app.calendar'));

    waitForSidebarTestId($page, 'sidebar-workspace-menu');

    $page->assertVisible('@sidebar-workspace-menu')
        ->click('@sidebar-workspace-menu');

    waitForSidebarTestId($page, 'logout-button');

    $page->assertVisible('@sidebar-menu-settings')
        ->assertVisible('@sidebar-menu-channels')
        ->assertMissing('@sidebar-menu-billing')
        ->assertVisible('@sidebar-menu-manage-team')
        ->assertVisible('@logout-button');
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

test('members whose posts need approval do not see channels or plans and billing in the sidebar menu', function () {
    config(['trypost.self_hosted' => false]);

    [
        'owner' => $owner,
        'member' => $requester,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        attachMember: true,
        setMemberCurrent: true,
    );

    $workspace->members()->updateExistingPivot($requester->id, membershipPivot('approval'));

    subscribeAccount($owner->account);

    $this->actingAs($requester->fresh());

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

test('the sidebar has no media library item', function () {
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

    waitForSidebarTestId($page, 'sidebar-workspace-menu');

    $ideasPath = route('app.create.ideas.index', absolute: false);
    $createItem = "nav-{$ideasPath}";
    $navItems = $page->script('Array.from(document.querySelectorAll("[data-testid^=\'nav-\']")).map((el) => el.dataset.testid)');

    expect($navItems)->toContain($createItem)
        ->and($navItems)->not->toContain('nav-/assets');

    $page->assertNoJavaScriptErrors();
});

test('the sidebar new button uses the strong brand color', function () {
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

test('the new menu uses the brand colors for post and idea', function () {
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
    $page->click('@sidebar-new');
    waitForSidebarTestId($page, 'sidebar-new-idea');

    $colors = $page->script(<<<'JS'
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

    expect($colors)->toBe(['rgb(221, 214, 254)', 'rgb(237, 233, 254)']);

    $page->assertNoJavaScriptErrors();
});

test('no sidebar menu item wraps onto a second line in any language', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    subscribeAccount($user->account);

    $wrapped = [];

    foreach (Locale::cases() as $locale) {
        $user->update(['locale' => $locale]);
        $this->actingAs($user->fresh());

        $page = visit(route('app.calendar'));
        waitForSidebarTestId($page, 'sidebar-workspace-menu');
        $page->click('@sidebar-workspace-menu');
        waitForSidebarTestId($page, 'sidebar-menu-manage-team');

        $lines = $page->script(<<<'JS'
            [...document.querySelectorAll('[role="menu"] [role="menuitem"]')]
                .map((item) => {
                    const walker = document.createTreeWalker(item, NodeFilter.SHOW_TEXT);
                    let lineCount = 0;
                    while (walker.nextNode()) {
                        if (!walker.currentNode.textContent.trim()) continue;
                        const range = document.createRange();
                        range.selectNodeContents(walker.currentNode);
                        const tops = new Set([...range.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top)));
                        lineCount = Math.max(lineCount, tops.size);
                    }
                    return [item.textContent.trim(), lineCount];
                })
                .filter(([, lineCount]) => lineCount > 1)
                .map(([text]) => text)
        JS);

        foreach ($lines as $text) {
            $wrapped[] = "{$locale->value}: {$text}";
        }
    }

    expect($wrapped)->toBe([]);
});

test('the main sidebar navigation is ordered create, publish, insights, repurpose', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarTestId($page, 'sidebar-logo');

    $paths = array_map(
        fn (string $url): string => (string) parse_url($url, PHP_URL_PATH),
        [route('app.create.ideas.index'), route('app.posts.index'), route('app.insights'), route('app.repurposes.index')],
    );

    expect($page->script(<<<'JS'
        Array.from(document.querySelectorAll('[data-testid^="nav-"]')).map((link) => new URL(link.href).pathname)
    JS))->toBe($paths);
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

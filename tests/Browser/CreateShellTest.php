<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForCreateShellTestId(mixed $page, string $testId): void
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

function waitForCreateShellMenuSettled(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100; i++) {
                const menu = document.querySelector('[data-testid="sidebar-new-menu"]');
                if (menu && menu.getAnimations({ subtree: true }).every((animation) => animation.playState !== 'running')) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function createShellUser(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('the new menu lists its items in order for an admin and each one opens its target', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    waitForCreateShellTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-member');
    waitForCreateShellMenuSettled($page);

    expect($page->script('[...document.querySelectorAll(\'[data-testid^="sidebar-new-"]:not([data-testid="sidebar-new-menu"]):not([data-testid$="-icon"])\')].map((el) => el.dataset.testid)'))
        ->toBe(['sidebar-new-post', 'sidebar-new-idea', 'sidebar-new-channel', 'sidebar-new-member']);
    expect($page->script("document.querySelector('[data-testid=\"sidebar-new-menu\"]').getBoundingClientRect().top >= document.querySelector('[data-testid=\"sidebar-new\"]').getBoundingClientRect().bottom"))->toBeTrue();

    $page->click('@sidebar-new-channel');
    waitForCreateShellTestId($page, 'connect-channel-dialog');
    $page->assertVisible('@connect-channel-dialog')
        ->keys('@connect-channel-dialog', 'Escape');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (!document.querySelector('[data-testid="connect-channel-dialog"]')) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-member');
    waitForCreateShellMenuSettled($page);
    $page->click('@sidebar-new-member');
    waitForCreateShellTestId($page, 'invite-member-dialog');
    $page->assertVisible('@invite-member-dialog')
        ->assertVisible('#invite-email')
        ->click('@invite-member-cancel');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (!document.querySelector('[data-testid="invite-member-dialog"]')) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-idea');
    waitForCreateShellMenuSettled($page);
    $page->click('@sidebar-new-idea');
    waitForCreateShellTestId($page, 'create-header');

    $page->assertVisible('@create-header')
        ->assertScript('location.pathname', parse_url(route('app.create.ideas.create'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
});

test('the new menu opens the post composer', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    waitForCreateShellTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-post');
    $page->click('@sidebar-new-post');
    waitForCreateShellTestId($page, 'post-composer-dialog');

    $page->assertVisible('@post-composer-dialog')->assertNoJavaScriptErrors();
});

test('the create nav item links to ideas and the ideas tab is current', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    $href = parse_url(route('app.create.ideas.index'), PHP_URL_PATH);
    waitForCreateShellTestId($page, "nav-{$href}");
    waitForCreateShellTestId($page, 'create-tab-ideas');

    $page->assertVisible("@nav-{$href}")
        ->assertScript("document.querySelector('[data-testid=\"nav-{$href}\"]').getAttribute('href')", $href)
        ->assertScript("document.querySelector('[data-testid=\"nav-{$href}\"]').dataset.active", 'true')
        ->assertVisible('@create-header')
        ->assertAttribute('@create-tab-ideas', 'aria-current', 'page')
        ->assertNoJavaScriptErrors();
});

test('the new menu hides connect channel and invite member from a member', function () {
    $owner = createShellUser();
    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $owner->currentWorkspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $owner->current_workspace_id]);
    $this->actingAs($member->fresh());

    $page = visit(route('app.create.ideas.index'));
    waitForCreateShellTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-idea');

    $page->assertVisible('@sidebar-new-post')
        ->assertMissing('@sidebar-new-channel')
        ->assertMissing('@sidebar-new-member')
        ->assertNoJavaScriptErrors();
});

test('the create tabs border keeps the same padding as the publish page', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'))->resize(1440, 900);
    waitForCreateShellTestId($page, 'create-tabs');

    $edges = $page->script(<<<'JS'
        (() => {
            const row = document.querySelector('[data-testid="create-tabs"]').parentElement.getBoundingClientRect();
            const panel = document.querySelector('[data-slot="sidebar-inset"]').getBoundingClientRect();
            return [Math.round(row.left - panel.left), Math.round(panel.right - row.right)];
        })()
    JS);

    expect($edges[0])->toBeGreaterThanOrEqual(32)
        ->and($edges[1])->toBeGreaterThanOrEqual(32);
    $page->assertNoJavaScriptErrors();
});

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

test('the new menu offers a post and an idea', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    waitForCreateShellTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-idea');

    $page->assertVisible('@sidebar-new-post')
        ->assertVisible('@sidebar-new-idea')
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=\"sidebar-new-post\"] svg')).color === getComputedStyle(document.documentElement).getPropertyValue('--info').trim() || getComputedStyle(document.querySelector('[data-testid=\"sidebar-new-post\"] svg')).color !== getComputedStyle(document.querySelector('[data-testid=\"sidebar-new-channel\"] svg')).color", true)
        ->click('@sidebar-new-idea');
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

test('the create nav item links to ideas and is active there', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    $href = parse_url(route('app.create.ideas.index'), PHP_URL_PATH);
    waitForCreateShellTestId($page, "nav-{$href}");

    $page->assertVisible("@nav-{$href}")
        ->assertScript("document.querySelector('[data-testid=\"nav-{$href}\"]').getAttribute('href')", $href)
        ->assertScript("document.querySelector('[data-testid=\"nav-{$href}\"]').dataset.active", 'true')
        ->assertNoJavaScriptErrors();
});

test('the ideas tab is the current page', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    waitForCreateShellTestId($page, 'create-tab-ideas');

    $page->assertVisible('@create-header')
        ->assertAttribute('@create-tab-ideas', 'aria-current', 'page')
        ->assertNoJavaScriptErrors();
});

test('the new menu lists post, idea, connect channel and invite member in order for an admin', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    waitForCreateShellTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-member');
    waitForCreateShellMenuSettled($page);

    expect($page->script('[...document.querySelectorAll(\'[data-testid^="sidebar-new-"]:not([data-testid="sidebar-new-menu"])\')].map((el) => el.dataset.testid)'))
        ->toBe(['sidebar-new-post', 'sidebar-new-idea', 'sidebar-new-channel', 'sidebar-new-member']);
    expect($page->script("document.querySelector('[data-testid=\"sidebar-new-menu\"]').getBoundingClientRect().top >= document.querySelector('[data-testid=\"sidebar-new\"]').getBoundingClientRect().bottom"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
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

test('connect a new channel from the new menu opens the connect dialog', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    waitForCreateShellTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-channel');
    $page->click('@sidebar-new-channel');
    waitForCreateShellTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('invite new member from the sidebar new menu opens the invite dialog', function () {
    $this->actingAs(createShellUser());

    $page = visit(route('app.create.ideas.index'));
    waitForCreateShellTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForCreateShellTestId($page, 'sidebar-new-member');
    waitForCreateShellMenuSettled($page);

    expect($page->script("document.querySelector('[data-testid=\"sidebar-new-menu\"]').getBoundingClientRect().top >= document.querySelector('[data-testid=\"sidebar-new\"]').getBoundingClientRect().bottom"))->toBeTrue();

    $page->click('@sidebar-new-member');
    waitForCreateShellTestId($page, 'invite-member-dialog');

    $page->assertVisible('@invite-member-dialog')
        ->assertVisible('#invite-email')
        ->assertNoJavaScriptErrors();
});

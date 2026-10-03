<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForCommandPaletteTestId(mixed $page, string $testId): void
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

function waitForCommandPaletteGone(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                if (!document.querySelector(sel)) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function waitForCommandPalettePath(mixed $page, string $path): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (window.location.pathname === '{$path}' && document.querySelector('[data-testid="publish-page"]')) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function commandPaletteUser(string $role): User
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);

    if ($role === 'admin') {
        return $owner->fresh();
    }

    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace->members()->attach($member->id, membershipPivot($role));
    $member->update(['current_workspace_id' => $workspace->id]);

    return $member->fresh();
}

function openCommandPalette(mixed $page): void
{
    waitForCommandPaletteTestId($page, 'app-content-shell');
    $page->keys('@app-content-shell', 'ControlOrMeta+k');
    waitForCommandPaletteTestId($page, 'command-palette-input');
}

test('the shortcut opens the palette and escape closes it', function () {
    $this->actingAs(commandPaletteUser('admin'));

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->assertVisible('@command-palette')
        ->assertVisible('@command-palette-footer')
        ->assertScript('document.activeElement?.dataset.testid', 'command-palette-input')
        ->assertAttribute('@command-palette-input', 'placeholder', 'Search channels, pages...');

    $page->keys('@command-palette-input', 'Escape');
    waitForCommandPaletteGone($page, 'command-palette');

    $page->assertMissing('@command-palette')->assertNoJavaScriptErrors();
});

test('the palette lists quick actions, navigation and channels', function () {
    $user = commandPaletteUser('admin');
    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
        'display_name' => 'Acme Page',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->assertVisible('@command-palette-group-actions')
        ->assertVisible('@command-palette-group-navigation')
        ->assertPresent('@command-palette-group-channels')
        ->assertPresent('@command-palette-group-connect')
        ->assertMissing('@command-palette-group-recent')
        ->assertMissing('@command-palette-group-settings')
        ->assertSeeIn('@command-palette-group-actions', 'Quick actions')
        ->assertSeeIn('@command-palette-item-action-create-post', 'Create new post')
        ->assertSeeIn('@command-palette-item-action-create-post', 'Start creating a new post')
        ->assertSeeIn('@command-palette-item-nav-publish', 'Publish')
        ->assertSeeIn('@command-palette-item-nav-ideas', 'Create → Ideas')
        ->assertSeeIn('@command-palette-item-nav-templates', 'Create → Templates')
        ->assertSeeIn('@command-palette-item-nav-feeds', 'Create → Feeds')
        ->assertPresent('@command-palette-item-nav-insights')
        ->assertPresent('@command-palette-item-nav-repurposes')
        ->assertPresent('@command-palette-item-nav-settings')
        ->assertSeeIn("@command-palette-item-channel-{$channel->id}", 'Acme Page')
        ->assertSeeIn("@command-palette-item-channel-{$channel->id}", 'LinkedIn')
        ->assertPresent('@command-palette-item-action-connect-channel')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('[data-testid=\"command-palette-item-action-create-post\"]').hasAttribute('data-highlighted')"))->toBeTrue()
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"command-palette-item-action-create-post-icon\"]')).alignSelf"))->toBe('flex-start')
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"command-palette-item-nav-publish-icon\"]')).alignSelf"))->toBe('center')
        ->and($page->script("document.querySelector('[data-testid=\"command-palette-item-nav-insights-icon\"]').classList.contains('tabler-icon-trending-up')"))->toBeTrue();
});

test('typing filters the list and exposes settings and insights pages', function () {
    $user = commandPaletteUser('admin');
    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
        'display_name' => 'Acme LinkedIn',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->type('@command-palette-input', 'set');
    waitForCommandPaletteTestId($page, 'command-palette-item-settings-profile');

    $page->assertVisible('@command-palette-group-settings')
        ->assertSeeIn('@command-palette-item-settings-profile', 'Settings → Profile')
        ->assertVisible('@command-palette-item-settings-members')
        ->assertMissing('@command-palette-item-nav-publish')
        ->assertMissing("@command-palette-item-channel-{$channel->id}")
        ->assertMissing("@command-palette-item-insights-{$channel->id}");

    $page->type('@command-palette-input', 'acme');
    waitForCommandPaletteTestId($page, "command-palette-item-insights-{$channel->id}");

    $page->assertVisible("@command-palette-item-channel-{$channel->id}")
        ->assertSeeIn("@command-palette-item-insights-{$channel->id}", 'Acme LinkedIn → Insights')
        ->assertMissing('@command-palette-group-settings')
        ->assertNoJavaScriptErrors();
});

test('an unmatched query shows the empty state', function () {
    $this->actingAs(commandPaletteUser('admin'));

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->type('@command-palette-input', 'zzqq');
    waitForCommandPaletteTestId($page, 'command-palette-empty');

    $page->assertSeeIn('@command-palette-empty', 'No results for "zzqq". Try different keywords.')
        ->assertMissing('@command-palette-group-actions')
        ->assertNoJavaScriptErrors();
});

test('selecting a channel opens its publish page and it shows up under recent', function () {
    $user = commandPaletteUser('admin');
    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
        'display_name' => 'Acme LinkedIn',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->type('@command-palette-input', 'acme');
    waitForCommandPaletteTestId($page, "command-palette-item-channel-{$channel->id}");
    $page->click("@command-palette-item-channel-{$channel->id}");
    $channelPath = parse_url(route('app.channels.publish', $channel), PHP_URL_PATH);
    waitForCommandPalettePath($page, $channelPath);

    expect($page->script('window.location.pathname'))->toBe($channelPath);
    $page->assertMissing('@command-palette');

    openCommandPalette($page);

    $page->assertVisible('@command-palette-group-recent')
        ->assertSeeIn('@command-palette-group-recent', 'Recent')
        ->assertSeeIn("@command-palette-recent-channel-{$channel->id}", 'Acme LinkedIn')
        ->assertNoJavaScriptErrors();
});

test('create new post opens the composer', function () {
    $this->actingAs(commandPaletteUser('admin'));

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->click('@command-palette-item-action-create-post');
    waitForCommandPaletteTestId($page, 'post-composer-dialog');

    $page->assertVisible('@post-composer-dialog')
        ->assertMissing('@command-palette')
        ->assertNoJavaScriptErrors();
});

test('invite and connect open their dialogs for admins', function () {
    $this->actingAs(commandPaletteUser('admin'));

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->click('@command-palette-item-action-invite-member');
    waitForCommandPaletteTestId($page, 'invite-member-dialog');
    $page->assertVisible('@invite-member-dialog')->assertMissing('@command-palette');

    $page->keys('@invite-member-dialog', 'Escape');
    waitForCommandPaletteGone($page, 'invite-member-dialog');

    openCommandPalette($page);
    $page->type('@command-palette-input', 'connect');
    waitForCommandPaletteTestId($page, 'command-palette-item-action-connect-channel');
    $page->click('@command-palette-item-action-connect-channel');
    waitForCommandPaletteTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('members who need approval can create but not invite or connect', function () {
    $this->actingAs(commandPaletteUser('approval'));

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->assertVisible('@command-palette-item-nav-publish')
        ->assertVisible('@command-palette-item-action-create-post')
        ->assertMissing('@command-palette-item-action-invite-member')
        ->assertMissing('@command-palette-item-action-connect-channel')
        ->assertNoJavaScriptErrors();
});

test('members can create but not invite or connect', function () {
    $this->actingAs(commandPaletteUser('member'));

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->assertVisible('@command-palette-item-action-create-post')
        ->assertVisible('@command-palette-item-action-create-idea')
        ->assertMissing('@command-palette-item-action-invite-member')
        ->assertMissing('@command-palette-item-action-connect-channel')
        ->assertNoJavaScriptErrors();
});

test('the sidebar channels search button opens the palette', function () {
    $user = commandPaletteUser('admin');
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForCommandPaletteTestId($page, 'sidebar-channels-label');

    $page->hover('@sidebar-channels-label')->hover('@sidebar-channels-search')->click('@sidebar-channels-search');
    waitForCommandPaletteTestId($page, 'command-palette-input');

    $page->assertVisible('@command-palette')->assertNoJavaScriptErrors();
});

test('the shortcut does not open the palette over the post composer', function () {
    $this->actingAs(commandPaletteUser('admin'));

    $page = visit(route('app.posts.index'));
    waitForCommandPaletteTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForCommandPaletteTestId($page, 'sidebar-new-post');
    $page->click('@sidebar-new-post');
    waitForCommandPaletteTestId($page, 'post-composer-dialog');

    $page->keys('@post-composer-dialog', 'ControlOrMeta+k');

    $page->assertMissing('@command-palette')->assertNoJavaScriptErrors();
});

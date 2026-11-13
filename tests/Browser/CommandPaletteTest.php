<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

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
    waitFor($page, '@app-content-shell');
    $page->keys('@app-content-shell', 'ControlOrMeta+k');
    waitFor($page, '@command-palette-input');
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
    waitForGone($page, '@command-palette');

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
    waitFor($page, '@command-palette-item-settings-profile');

    $page->assertVisible('@command-palette-group-settings')
        ->assertSeeIn('@command-palette-item-settings-profile', 'Settings → Profile')
        ->assertVisible('@command-palette-item-settings-members')
        ->assertMissing('@command-palette-item-nav-publish')
        ->assertMissing("@command-palette-item-channel-{$channel->id}")
        ->assertMissing("@command-palette-item-insights-{$channel->id}");

    $page->type('@command-palette-input', 'acme');
    waitFor($page, "@command-palette-item-insights-{$channel->id}");

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
    waitFor($page, '@command-palette-empty');

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
    waitFor($page, "@command-palette-item-channel-{$channel->id}");
    $page->click("@command-palette-item-channel-{$channel->id}");
    $channelPath = parse_url(route('app.channels.publish', $channel), PHP_URL_PATH);
    waitForPath($page, $channelPath);
    waitForState($page, '@publish-page', 'true');

    expect($page->script('window.location.pathname'))->toBe($channelPath);
    $page->assertMissing('@command-palette');

    openCommandPalette($page);

    $page->assertVisible('@command-palette-group-recent')
        ->assertSeeIn('@command-palette-group-recent', 'Recent')
        ->assertSeeIn("@command-palette-recent-channel-{$channel->id}", 'Acme LinkedIn')
        ->assertNoJavaScriptErrors();
});

test('invite and connect open their dialogs for admins', function () {
    $this->actingAs(commandPaletteUser('admin'));

    $page = visit(route('app.posts.index'));
    openCommandPalette($page);

    $page->click('@command-palette-item-action-invite-member');
    waitFor($page, '@invite-member-dialog');
    $page->assertVisible('@invite-member-dialog')->assertMissing('@command-palette');

    $page->keys('@invite-member-dialog', 'Escape');
    waitForGone($page, '@invite-member-dialog');

    openCommandPalette($page);
    $page->type('@command-palette-input', 'connect');
    waitFor($page, '@command-palette-item-action-connect-channel');
    $page->click('@command-palette-item-action-connect-channel');
    waitFor($page, '@connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
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

test('the shortcut does not open the palette over the post composer', function () {
    $this->actingAs(commandPaletteUser('admin'));

    $page = visit(route('app.posts.index'));
    waitFor($page, '@sidebar-new');
    $page->click('@sidebar-new');
    waitFor($page, '@sidebar-new-post');
    $page->click('@sidebar-new-post');
    waitFor($page, '@post-composer-dialog');

    $page->keys('@post-composer-dialog', 'ControlOrMeta+k');

    $page->assertMissing('@command-palette')->assertNoJavaScriptErrors();
});

test('when the workspace changes while the page stays mounted, a channel picked in the palette is kept under the new workspace', function () {
    $user = commandPaletteUser('admin');
    $first = $user->currentWorkspace;
    $second = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
        'name' => 'Beta Workspace',
    ]);
    $second->members()->attach($user->id, membershipPivot('admin'));
    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $second->id,
        'display_name' => 'Beta LinkedIn',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week']));
    waitFor($page, '@sidebar-workspace-menu');
    $switchToSecond = function () use ($page, $second): void {
        $page->click('@sidebar-workspace-menu');
        waitFor($page, '@sidebar-workspaces-trigger');
        $page->click('@sidebar-workspaces-trigger');
        waitFor($page, "@sidebar-workspace-{$second->id}");
        $page->click("@sidebar-workspace-{$second->id}");
        waitForGone($page, "@sidebar-workspace-{$second->id}");
    };
    $switchToSecond();
    $page->script('new Promise((resolve) => setTimeout(resolve, 1500))');
    $user->refresh();
    $switchToSecond();
    waitForText($page, '@sidebar-channels', 'Beta LinkedIn');

    openCommandPalette($page);
    $page->type('@command-palette-input', 'beta');
    waitFor($page, "@command-palette-item-channel-{$channel->id}");
    $page->click("@command-palette-item-channel-{$channel->id}");
    waitForPath($page, route('app.channels.publish', $channel, absolute: false));

    expect(browserStorageItem($page, $user, 'commandPalette.recent', $second->id))->toBe(["channel-{$channel->id}"])
        ->and(browserStorageItem($page, $user, 'commandPalette.recent', $first->id))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

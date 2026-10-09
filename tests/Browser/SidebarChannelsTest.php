<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForSidebarChannelsTestId(mixed $page, string $testId): void
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

function sidebarChannelsUser(string $role): User
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

test('admins see channels with settings and connect controls', function () {
    $user = sidebarChannelsUser('admin');
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$channel->id}");

    $page->assertVisible('@sidebar-channels')
        ->assertVisible('@sidebar-channels-settings')
        ->assertVisible('@sidebar-channels-connect')
        ->assertMissing("@sidebar-channel-{$channel->id}-publish")
        ->assertMissing('@nav-/accounts')
        ->assertNoJavaScriptErrors();
});

test('a channel publish link leads to that channel publish page', function () {
    $user = sidebarChannelsUser('admin');
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$channel->id}");
    $page->click("@sidebar-channel-{$channel->id}");
    waitForSidebarChannelsTestId($page, 'publish-channel-settings');

    expect($page->script('window.location.pathname'))->toBe(parse_url(route('app.channels.publish', $channel), PHP_URL_PATH));
    $page->assertVisible('@publish-page')
        ->assertVisible("@sidebar-channel-{$channel->id}-publish")
        ->assertNoJavaScriptErrors();
});

test('members see channels without admin controls', function () {
    $user = sidebarChannelsUser('member');
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$channel->id}");

    $page->assertVisible("@sidebar-channel-{$channel->id}")
        ->assertMissing('@sidebar-channels-settings')
        ->assertMissing('@sidebar-channels-connect')
        ->assertNoJavaScriptErrors();
});

test('a lost connection is flagged on the channel', function () {
    $user = sidebarChannelsUser('admin');
    $channel = SocialAccount::factory()->linkedin()->tokenExpired()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$channel->id}-lost");

    $page->assertVisible("@sidebar-channel-{$channel->id}-lost")
        ->assertMissing("@channel-avatar-disconnected-{$channel->id}")
        ->assertNoJavaScriptErrors();
});

test('a lost connection shows one dot on the avatar for members who cannot reconnect', function () {
    $user = sidebarChannelsUser('member');
    $channel = SocialAccount::factory()->linkedin()->tokenExpired()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, "channel-avatar-disconnected-{$channel->id}");

    $page->assertVisible("@channel-avatar-disconnected-{$channel->id}")
        ->assertMissing("@sidebar-channel-{$channel->id}-lost")
        ->assertNoJavaScriptErrors();
});

test('an empty workspace offers to connect a channel', function () {
    $this->actingAs(sidebarChannelsUser('admin'));

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, 'sidebar-channels-empty');

    $page->assertSeeIn('@sidebar-channels-label', __('sidebar.connect_channels'))
        ->assertVisible('@sidebar-channels-empty-connect-instagram')
        ->assertVisible('@sidebar-channels-empty-connect-tiktok')
        ->assertVisible('@sidebar-channels-empty-connect-linkedin')
        ->assertVisible('@sidebar-channels-settings')
        ->assertVisible('@sidebar-channels-connect');

    expect($page->script("(() => { const button = document.querySelector('[data-testid=\"sidebar-channels-empty-connect-instagram\"]'); const style = getComputedStyle(button); return [button.querySelector('svg') !== null, button.querySelector('img') === null, style.backgroundImage, style.backgroundColor, style.borderTopStyle]; })()"))
        ->toBe([true, true, 'none', 'rgba(0, 0, 0, 0)', 'solid']);

    $page->hover('@sidebar-channels-empty-connect-instagram');
    $page->script(<<<'JS'
        new Promise((resolve) => {
            const check = () => document.querySelector('[data-slot="tooltip-content"], [role="tooltip"]') ? resolve(true) : requestAnimationFrame(check);
            check();
        })
    JS);

    expect($page->script(<<<'JS'
        (() => {
            const button = document.querySelector('[data-testid="sidebar-channels-empty-connect-instagram"]').getBoundingClientRect();
            const tooltip = [...document.querySelectorAll('[data-slot="tooltip-content"], [role="tooltip"]')].map((el) => el.getBoundingClientRect()).find((rect) => rect.height > 0);
            return tooltip.bottom <= button.top;
        })()
    JS))->toBeTrue();

    $page->click('@sidebar-channels-empty');
    waitForSidebarChannelsTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-linkedin')->assertNoJavaScriptErrors();
});

test('the channel new-post shortcut opens the composer with that channel selected', function () {
    $user = sidebarChannelsUser('admin');
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $other = SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$channel->id}");
    $page->click("@sidebar-channel-{$channel->id}-new-post");
    waitForSidebarChannelsTestId($page, "composer-account-{$channel->id}");

    $page->assertVisible("@composer-account-{$channel->id}")
        ->assertMissing("@composer-account-{$other->id}")
        ->assertNoJavaScriptErrors();
});

function waitForSidebarChannelsScript(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if ({$condition}) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('the chevron expands and collapses a channel submenu with an animated grid row', function () {
    $user = sidebarChannelsUser('admin');
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$channel->id}");

    $submenu = "document.querySelector('[data-testid=\"sidebar-channel-{$channel->id}-submenu\"]')";

    expect($page->script("getComputedStyle({$submenu}).transitionProperty"))->toContain('grid-template-rows')
        ->and($page->script("getComputedStyle({$submenu}).transitionDuration"))->toContain('0.2s');

    $page->assertMissing("@sidebar-channel-{$channel->id}-publish")
        ->click("@sidebar-channel-{$channel->id}-toggle");

    waitForSidebarChannelsScript($page, "{$submenu}.getBoundingClientRect().height > 60");

    expect($page->script("document.querySelector('[data-testid=\"sidebar-channel-{$channel->id}-toggle\"]').getAttribute('aria-expanded')"))->toBe('true');
    $page->assertVisible("@sidebar-channel-{$channel->id}-publish")
        ->click("@sidebar-channel-{$channel->id}-toggle");

    waitForSidebarChannelsScript($page, "getComputedStyle({$submenu}).visibility === 'hidden' && {$submenu}.getBoundingClientRect().height === 0");

    $page->assertMissing("@sidebar-channel-{$channel->id}-publish")
        ->assertNoJavaScriptErrors();

    expect(parse_url((string) $page->script('window.location.href'), PHP_URL_PATH))->toBe(parse_url(route('app.posts.index'), PHP_URL_PATH));
});

test('the sidebar cannot be collapsed', function () {
    $user = sidebarChannelsUser('admin');
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, 'sidebar-new');

    $sidebar = "document.querySelector('[data-slot=\"sidebar\"]')";

    $page->assertMissing('@sidebar-collapse');

    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', { key: 'b', metaKey: true, ctrlKey: true, bubbles: true }))");
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    expect($page->script("{$sidebar}.dataset.state"))->toBe('expanded')
        ->and($page->script("Math.round({$sidebar}.lastElementChild.getBoundingClientRect().width)"))->toBe(240);

    $page->assertNoJavaScriptErrors();
});

test('an expanded channel shows its scheduled count on the Publish item', function () {
    $user = sidebarChannelsUser('admin');
    $channel = SocialAccount::factory()->threads()->create(['workspace_id' => $user->current_workspace_id]);
    $empty = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);

    foreach (range(1, 3) as $ignored) {
        Post::factory()->forAccount($channel)->scheduled()->create(['user_id' => $user->id]);
    }

    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, "sidebar-channel-count-{$channel->id}");

    expect(trim((string) $page->script("document.querySelector('[data-testid=\"sidebar-channel-count-{$channel->id}\"]').textContent")))->toBe('3');

    $page->click("@sidebar-channel-{$channel->id}-toggle");
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$channel->id}-publish-count");

    $page->assertSeeIn('@sidebar-publish-count', '3')
        ->assertSeeIn("@sidebar-channel-{$channel->id}-publish-count", '3')
        ->click("@sidebar-channel-{$empty->id}-toggle");
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$empty->id}-publish");

    $page->assertMissing("@sidebar-channel-{$empty->id}-publish-count")
        ->assertNoJavaScriptErrors();
});

test('keyboard focus reveals the channels header actions', function () {
    $user = sidebarChannelsUser('member');
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, 'sidebar-channels-label');

    $actions = "document.querySelector('[data-testid=\"sidebar-channels-actions\"]')";
    $page->script("document.querySelector('[data-testid=\"sidebar-channels-search\"]').focus()");
    waitForSidebarChannelsScript($page, "getComputedStyle({$actions}).opacity === '1'");

    expect($page->script("getComputedStyle({$actions}).opacity"))->toBe('1');
    $page->assertMissing('@sidebar-channels-settings')
        ->assertMissing('@sidebar-channels-connect')
        ->assertNoJavaScriptErrors();
});

test('the channels search button shows its shortcut and opens the command palette', function () {
    $user = sidebarChannelsUser('admin');
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, 'sidebar-channels-label');

    $page->hover('@sidebar-channels-label')->hover('@sidebar-channels-search');
    waitForSidebarChannelsTestId($page, 'sidebar-channels-search-tooltip');

    $isMac = (bool) $page->script('/Mac|iPhone|iPad/.test(navigator.platform)');

    expect($page->script("document.querySelector('[data-testid=\"sidebar-channels-search\"]').getAttribute('aria-keyshortcuts')"))->toBe($isMac ? 'Meta+K' : 'Control+K')
        ->and($page->script("[...document.querySelectorAll('[data-testid=\"sidebar-channels-search-shortcut\"] > kbd')].map((key) => key.textContent.trim())"))->toBe($isMac ? ['⌘', 'K'] : ['Ctrl', 'K']);

    $page->assertSeeIn('@sidebar-channels-search-tooltip', 'Search channels');

    expect($page->script(<<<'JS'
        (() => {
            const tooltip = getComputedStyle(document.querySelector('[data-testid="sidebar-channels-search-tooltip"]'));
            const key = getComputedStyle(document.querySelector('[data-testid="sidebar-channels-search-shortcut"] > kbd'));
            return key.color === tooltip.color;
        })()
    JS))->toBeTrue();

    $page->click('@sidebar-channels-search')->assertNoJavaScriptErrors();
});

test('the channels settings and connect actions show a tooltip above them', function (string $action) {
    $user = sidebarChannelsUser('admin');
    $user->update(['theme' => 'dark']);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarChannelsTestId($page, 'sidebar-channels-label');

    $page->hover('@sidebar-channels-label')->hover("@sidebar-channels-{$action}");
    waitForSidebarChannelsTestId($page, "sidebar-channels-{$action}-tooltip");

    $page->assertSeeIn("@sidebar-channels-{$action}-tooltip", $action === 'settings' ? __('channels.settings') : __('channels.connect'));

    expect($page->script(<<<JS
        (() => {
            const button = document.querySelector('[data-testid="sidebar-channels-{$action}"]').getBoundingClientRect();
            const tooltip = document.querySelector('[data-testid="sidebar-channels-{$action}-tooltip"]').getBoundingClientRect();
            return tooltip.bottom <= button.top;
        })()
    JS))->toBeTrue();
    $page->assertNoJavaScriptErrors();
})->with(['settings']);

test('only the channel list scrolls while the main nav and the footer stay fixed', function () {
    $user = sidebarChannelsUser('admin');
    $channels = SocialAccount::factory()->linkedin()->count(20)->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1280, 700);
    waitForSidebarChannelsTestId($page, "sidebar-channel-{$channels->first()->id}");

    $layout = fn (): array => $page->script(<<<'JS'
        (() => {
            const list = document.querySelector('[data-testid="sidebar-channels-list"]');
            const content = list.closest('[data-sidebar="content"]');
            const top = (sel) => Math.round(document.querySelector(sel).getBoundingClientRect().top);
            return {
                listScrollable: list.scrollHeight > list.clientHeight,
                contentScrollable: content.scrollHeight > content.clientHeight,
                listScrollTop: list.scrollTop,
                listRightGap: Math.round(content.getBoundingClientRect().right - list.getBoundingClientRect().right),
                newTop: top('[data-testid="sidebar-new"]'),
                footerTop: top('[data-testid="sidebar-workspace-menu"]'),
            };
        })()
    JS);

    $before = $layout();
    expect($before['listScrollable'])->toBeTrue()
        ->and($before['contentScrollable'])->toBeFalse()
        ->and($before['listRightGap'])->toBe(0);

    $page->script("document.querySelector('[data-testid=\"sidebar-channels-list\"]').scrollTop = 10000");
    $after = $layout();

    expect($after['listScrollTop'])->toBeGreaterThan(0)
        ->and($after['newTop'])->toBe($before['newTop'])
        ->and($after['footerTop'])->toBe($before['footerTop']);

    $page->assertNoJavaScriptErrors();
});

test('the collapsed sidebar shows every channel network badge without clipping it', function () {
    $user = sidebarChannelsUser('admin');
    $channels = collect([
        SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]),
        SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]),
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channels->first()->id))->resize(1280, 900);
    waitForSidebarChannelsTestId($page, 'sidebar-footer-toggle');
    $page->click('@sidebar-footer-toggle');
    waitForSidebarChannelsScript($page, "document.querySelector('[data-slot=\"sidebar\"][data-state]')?.dataset.state === 'collapsed'");
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    foreach ($channels as $channel) {
        $clipping = $page->script(<<<JS
            (() => {
                const link = document.querySelector('[data-testid="sidebar-channel-{$channel->id}"]');
                const badge = [...link.querySelectorAll('*')].find((el) => getComputedStyle(el).position === 'absolute' && el.querySelector('img'));
                const box = badge.getBoundingClientRect();
                const clipped = [];
                for (let el = badge.parentElement; el && el !== document.body; el = el.parentElement) {
                    const style = getComputedStyle(el);
                    if (style.overflowX === 'visible' && style.overflowY === 'visible') continue;
                    const area = el.getBoundingClientRect();
                    if (box.left < area.left || box.top < area.top || box.right > area.right || box.bottom > area.bottom) {
                        clipped.push(el.dataset.testid ?? el.dataset.sidebar ?? el.tagName);
                    }
                }
                return { width: Math.round(box.width), clipped };
            })()
        JS);

        expect($clipping)->toBe(['width' => 18, 'clipped' => []]);
    }

    $page->assertNoJavaScriptErrors();
});

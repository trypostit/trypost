<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForChannelsSettingsTestId(mixed $page, string $testId): void
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

function channelsSettingsAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('channels are listed one per row with settings and actions', function () {
    $user = channelsSettingsAdmin();
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, "channel-row-{$channel->id}");

    $page->assertVisible("@channel-settings-{$channel->id}")
        ->click("@channel-menu-{$channel->id}");
    waitForChannelsSettingsTestId($page, "channel-disconnect-{$channel->id}");

    $page->assertMissing("@channel-toggle-{$channel->id}")
        ->assertSeeIn("@channel-menu-reconnect-{$channel->id}", __('channels.refresh_connection'))
        ->assertVisible("@channel-disconnect-{$channel->id}")
        ->assertNoJavaScriptErrors();
});

test('disconnecting a channel asks for the translated disconnect keyword, not the handle', function () {
    $user = channelsSettingsAdmin();
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $keyword = __('channels.disconnect_modal.keyword');

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, "channel-row-{$channel->id}");

    $page->click("@channel-menu-{$channel->id}");
    waitForChannelsSettingsTestId($page, "channel-disconnect-{$channel->id}");

    $page->click("@channel-disconnect-{$channel->id}")
        ->assertVisible('@confirm-delete-modal')
        ->assertSeeIn('@confirm-delete-modal', __('channels.disconnect_modal.title_named', ['name' => $channel->display_name ?: $channel->username]))
        ->assertSeeIn('@disconnect-channel-card', $channel->display_name ?: $channel->username)
        ->assertSeeIn('@disconnect-channel-refresh', __('channels.refresh_connection'))
        ->assertSeeIn('@confirm-delete-description', __('channels.disconnect_modal.description'))
        ->fill('@confirm-delete-input', $channel->username)
        ->assertAttribute('@confirm-delete-action', 'disabled', '')
        ->fill('@confirm-delete-input', mb_strtoupper($keyword))
        ->assertAttribute('@confirm-delete-action', 'disabled', '')
        ->fill('@confirm-delete-input', $keyword)
        ->click('@confirm-delete-action')
        ->assertMissing('@confirm-delete-modal')
        ->assertNoJavaScriptErrors();

    expect(SocialAccount::find($channel->id))->toBeNull();
});

test('a lost connection offers reconnect on the row', function () {
    $user = channelsSettingsAdmin();
    $channel = SocialAccount::factory()->linkedin()->tokenExpired()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, "channel-reconnect-{$channel->id}");

    $page->assertVisible("@channel-reconnect-{$channel->id}")->assertNoJavaScriptErrors();
});

test('a channel without a display name shows its username', function () {
    $user = channelsSettingsAdmin();
    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
        'display_name' => null,
        'avatar_url' => null,
        'username' => 'fallback-handle',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, "channel-name-{$channel->id}");

    expect($page->script("document.querySelector('[data-testid=\"channel-name-{$channel->id}\"]').textContent.trim()"))
        ->toBe('fallback-handle');

    $page = visit(route('app.posts.index'));
    waitForChannelsSettingsTestId($page, "sidebar-channel-{$channel->id}");
    expect($page->script("document.querySelector('[data-testid=\"sidebar-channel-{$channel->id}\"]').textContent"))
        ->toContain('fallback-handle');
    $page->assertNoJavaScriptErrors();
});

test('an empty workspace shows only the centered illustration with a connect button', function () {
    $this->actingAs(channelsSettingsAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, 'channels-empty');

    $page->assertVisible('@channels-empty-illustration')
        ->assertSeeIn('@channels-empty', __('channels.empty'))
        ->assertMissing('@header-title')
        ->assertMissing('@channels-connect');

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]').getBoundingClientRect();
            const empty = document.querySelector('[data-testid="channels-empty"]').getBoundingClientRect();
            return Math.abs((empty.top + empty.height / 2) - (scroller.top + scroller.height / 2)) <= 24
                && Math.abs((empty.left + empty.width / 2) - (scroller.left + scroller.width / 2)) <= 24;
        })()
    JS))->toBeTrue();

    $page->click('@channels-empty-connect');
    waitForChannelsSettingsTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('a workspace with channels keeps the header and the list at the top', function () {
    $user = channelsSettingsAdmin();
    SocialAccount::factory()->linkedin()->count(12)->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, 'header-title');

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]');
            const top = document.querySelector('[data-testid="header-title"]').getBoundingClientRect().top - scroller.getBoundingClientRect().top;
            const scrolls = scroller.scrollHeight > scroller.clientHeight;
            scroller.scrollTop = scroller.scrollHeight;
            return [document.querySelector('[data-testid="settings-centered"]') === null, document.querySelector('[data-testid="channels-empty"]') === null, top < 120, scrolls, scroller.scrollTop > 0];
        })()
    JS))->toBe([true, true, true, true, true]);

    $page->assertVisible('@channels-connect')->assertNoJavaScriptErrors();
});

test('a channel with a lost connection shows the disconnected dot in the list and the channel header', function () {
    $user = channelsSettingsAdmin();
    $lost = SocialAccount::factory()->linkedin()->tokenExpired()->create(['workspace_id' => $user->current_workspace_id]);
    $healthy = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, "channel-row-{$lost->id}");

    $page->assertVisible("@channel-avatar-disconnected-{$lost->id}")
        ->assertMissing("@channel-avatar-disconnected-{$healthy->id}")
        ->assertSeeIn("@channel-connection-lost-{$lost->id}", __('channels.connection_lost_hint'))
        ->assertVisible("@channel-reconnect-{$lost->id}")
        ->assertMissing("@channel-connection-lost-{$healthy->id}")
        ->assertMissing("@channel-reconnect-{$healthy->id}")
        ->assertNoJavaScriptErrors();

    $page = visit(route('app.channels.publish', $lost));
    waitForChannelsSettingsTestId($page, 'header-title');

    $page->assertVisible("@channel-avatar-disconnected-{$lost->id}")->assertNoJavaScriptErrors();
});

test('refresh connection in the disconnect dialog closes it instead of disconnecting', function () {
    $user = channelsSettingsAdmin();
    $channel = SocialAccount::factory()->mastodon()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, "channel-row-{$channel->id}");

    $page->click("@channel-menu-{$channel->id}");
    waitForChannelsSettingsTestId($page, "channel-disconnect-{$channel->id}");

    $page->click("@channel-disconnect-{$channel->id}");
    waitForChannelsSettingsTestId($page, 'disconnect-channel-refresh');

    $page->click('@disconnect-channel-refresh')
        ->assertMissing('@confirm-delete-modal')
        ->assertNoJavaScriptErrors();

    expect(SocialAccount::find($channel->id))->not->toBeNull();
});

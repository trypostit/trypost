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

test('the disconnect dialog shows the keyword with a copy button, like the other confirmations', function () {
    $user = channelsSettingsAdmin();
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $keyword = __('channels.disconnect_modal.keyword');

    $page = visit(route('app.workspace.channels'));
    waitForChannelsSettingsTestId($page, "channel-row-{$channel->id}");
    $page->click("@channel-menu-{$channel->id}");
    waitForChannelsSettingsTestId($page, "channel-disconnect-{$channel->id}");
    $page->click("@channel-disconnect-{$channel->id}");
    waitForChannelsSettingsTestId($page, 'confirm-delete-copy-keyword');

    $page->assertSeeIn('@confirm-delete-modal', __('common.confirm_modal.type'))
        ->assertSeeIn('@confirm-delete-modal', __('common.confirm_modal.to_confirm'))
        ->assertDontSee('"'.$keyword.'"');

    expect($page->script("document.querySelector('[data-testid=\"confirm-delete-modal\"] code')?.textContent.trim()"))->toBe($keyword);

    $page->script("Object.defineProperty(navigator, 'clipboard', { value: { writeText: (text) => { window.__copied = text; return Promise.resolve(); } }, configurable: true })");
    $page->click('@confirm-delete-copy-keyword')
        ->assertVisible('@confirm-delete-keyword-copied');

    expect($page->script('window.__copied'))->toBe($keyword);
    $page->assertNoJavaScriptErrors();
});

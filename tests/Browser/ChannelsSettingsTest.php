<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;

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
    waitFor($page, "@channel-row-{$channel->id}");

    $page->assertVisible("@channel-settings-{$channel->id}")
        ->click("@channel-menu-{$channel->id}");
    waitFor($page, "@channel-disconnect-{$channel->id}");

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
    waitFor($page, "@channel-row-{$channel->id}");

    $page->click("@channel-menu-{$channel->id}");
    waitFor($page, "@channel-disconnect-{$channel->id}");

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
    waitFor($page, '@channels-empty');

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
    waitFor($page, '@connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('a channel with a lost connection shows the disconnected dot in the list and the channel header', function () {
    $user = channelsSettingsAdmin();
    $lost = SocialAccount::factory()->linkedin()->tokenExpired()->create(['workspace_id' => $user->current_workspace_id]);
    $healthy = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitFor($page, "@channel-row-{$lost->id}");

    $page->assertVisible("@channel-avatar-disconnected-{$lost->id}")
        ->assertMissing("@channel-avatar-disconnected-{$healthy->id}")
        ->assertSeeIn("@channel-connection-lost-{$lost->id}", __('channels.list.connection_lost'))
        ->assertVisible("@channel-reconnect-{$lost->id}")
        ->assertVisible("@channel-settings-{$lost->id}")
        ->assertMissing("@channel-connection-lost-{$healthy->id}")
        ->assertMissing("@channel-reconnect-{$healthy->id}")
        ->assertSeeIn("@channel-reconnect-{$lost->id}", __('channels.reconnect'))
        ->assertNoJavaScriptErrors();

    $page = visit(route('app.channels.publish', $lost));
    waitFor($page, '@header-title');

    $page->assertVisible("@channel-avatar-disconnected-{$lost->id}")->assertNoJavaScriptErrors();
});

test('refresh connection in the disconnect dialog closes it instead of disconnecting', function () {
    $user = channelsSettingsAdmin();
    $channel = SocialAccount::factory()->mastodon()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitFor($page, "@channel-row-{$channel->id}");

    $page->click("@channel-menu-{$channel->id}");
    waitFor($page, "@channel-disconnect-{$channel->id}");

    $page->click("@channel-disconnect-{$channel->id}");
    waitFor($page, '@disconnect-channel-refresh');

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
    waitFor($page, "@channel-row-{$channel->id}");
    $page->click("@channel-menu-{$channel->id}");
    waitFor($page, "@channel-disconnect-{$channel->id}");
    $page->click("@channel-disconnect-{$channel->id}");
    waitFor($page, '@confirm-delete-copy-keyword');

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

test('an instagram login channel offers to link a facebook page, which reconnects that card through facebook', function () {
    $user = channelsSettingsAdmin();
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $user->current_workspace_id]);
    $viaFacebook = SocialAccount::factory()->create(['workspace_id' => $user->current_workspace_id, 'platform' => Platform::InstagramFacebook]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitFor($page, "@channel-row-{$viaFacebook->id}");
    $page->click("@channel-menu-{$viaFacebook->id}");
    waitFor($page, "@channel-menu-reconnect-{$viaFacebook->id}");
    $page->assertMissing("@channel-menu-link-facebook-{$viaFacebook->id}")
        ->keys("@channel-menu-reconnect-{$viaFacebook->id}", 'Escape');

    $page->click("@channel-menu-{$instagram->id}");
    waitFor($page, "@channel-menu-link-facebook-{$instagram->id}");
    $page->assertSeeIn("@channel-menu-link-facebook-{$instagram->id}", __('channels.link_facebook_page'))
        ->click("@channel-menu-link-facebook-{$instagram->id}");
    waitFor($page, '@instagram-facebook-requirements');

    $page->script(<<<'JS'
        document.addEventListener('inertia:before', (event) => {
            window.__connectUrl = event.detail.visit.url.href;
            event.preventDefault();
        });
    JS);
    $page->click('@instagram-facebook-requirements-connect');
    waitForCondition($page, 'window.__connectUrl !== undefined');

    expect($page->script('window.__connectUrl'))->toContain("reconnect={$instagram->id}")
        ->and($page->script('new URL(window.__connectUrl).pathname'))->toBe((string) parse_url(route('app.social.instagram-facebook.connect'), PHP_URL_PATH));
    $page->assertNoJavaScriptErrors();
});

test('each channel row shows its weekly posting goal without a popover, or a link to set one', function () {
    $user = channelsSettingsAdmin();
    connectAiTo($user);
    $withGoal = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id, 'posting_goal' => 5]);
    $withoutGoal = SocialAccount::factory()->create(['workspace_id' => $user->current_workspace_id, 'platform' => Platform::X, 'posting_goal' => null]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'))->resize(1280, 900);
    waitFor($page, "@channel-goal-{$withGoal->id}-progress");

    $page->assertSeeIn("@channel-goal-{$withGoal->id}-progress", '0/5');

    $page->click("@channel-goal-{$withGoal->id}-progress");

    expect($page->script("document.querySelectorAll('[data-testid=\"channel-goal-{$withGoal->id}-popover\"]').length"))->toBe(0);

    $page->click("@channel-goal-set-{$withoutGoal->id}");
    waitForPath($page, route('app.channels.settings', $withoutGoal, absolute: false));

    $page->assertNoJavaScriptErrors();
});

test('each channel row shows its time zone and posting times, and the list is searched and filtered by network', function () {
    $user = channelsSettingsAdmin();
    connectAiTo($user);
    $linkedin = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
        'display_name' => 'Acme LinkedIn',
        'timezone' => 'America/Sao_Paulo',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00'),
    ]);
    $x = SocialAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform' => Platform::X,
        'display_name' => 'Beta X',
        'timezone' => 'UTC',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'))->resize(1280, 900);
    waitFor($page, "@channel-row-{$linkedin->id}");

    $page->assertSeeIn("@channel-timezone-{$linkedin->id}", 'Sao Paulo')
        ->assertSeeIn("@channel-posting-times-{$linkedin->id}", __('channels.list.posting_times_per_week', ['count' => 2]))
        ->assertSeeIn("@channel-posting-times-{$x->id}", __('channels.settings_page.day_empty'))
        ->assertSeeIn('@settings-page-total', '(2)');

    $page->type('@header-search-input', 'beta');
    waitForGone($page, "@channel-row-{$linkedin->id}");
    $page->assertVisible("@channel-row-{$x->id}");

    $page->type('@header-search-input', 'nothing like this');
    waitFor($page, '@channels-no-results');

    $page->clear('@header-search-input');
    waitFor($page, "@channel-row-{$linkedin->id}");

    $page->click('@channels-network-filter');
    waitFor($page, '@channels-network-option-linkedin');
    waitForState($page, '@channels-network-option-linkedin', 'element.getBoundingClientRect().width > 0 && element.getBoundingClientRect().top >= 0 && element.getBoundingClientRect().bottom <= window.innerHeight');
    $page->click('@channels-network-option-linkedin');
    waitForGone($page, "@channel-row-{$x->id}");

    $page->assertVisible("@channel-row-{$linkedin->id}")
        ->assertNoJavaScriptErrors();
});

test('the channels list loads its next page without repeating a channel', function () {
    config(['app.pagination.default' => 2]);
    $user = channelsSettingsAdmin();
    connectAiTo($user);
    $channels = collect(range(0, 2))->map(fn (int $position) => SocialAccount::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform' => Platform::X,
        'position' => $position,
    ]));
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'))->resize(1280, 900);
    waitFor($page, "@channel-row-{$channels[2]->id}");
    waitForCount($page, '[data-testid^="channel-row-"]', 3);

    $page->assertSeeIn('@settings-page-total', '(3)')
        ->assertNoJavaScriptErrors();
});

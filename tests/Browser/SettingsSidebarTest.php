<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

function waitForSettingsSidebarTestId(mixed $page, string $testId): void
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

function settingsSidebarUser(string $role): User
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

test('settings pages swap the app sidebar for the settings sidebar', function () {
    $this->actingAs(settingsSidebarUser('admin'));

    $page = visit(route('app.profile.edit'));
    waitForSettingsSidebarTestId($page, 'settings-sidebar');

    $page->assertVisible('@settings-sidebar')
        ->assertVisible('@settings-nav-profile')
        ->assertVisible('@settings-nav-preferences')
        ->assertVisible('@settings-nav-channels')
        ->assertPresent('[data-testid="settings-nav-channels"] svg.tabler-icon-layout-grid')
        ->assertVisible('@settings-nav-signatures')
        ->assertVisible('@settings-nav-webhooks')
        ->assertMissing('@sidebar-new')
        ->assertMissing('@settings-tab-profile')
        ->assertNoJavaScriptErrors();
});

test('members only see the settings they may use', function () {
    $this->actingAs(settingsSidebarUser('member'));

    $page = visit(route('app.profile.edit'));
    waitForSettingsSidebarTestId($page, 'settings-sidebar');

    $page->assertVisible('@settings-nav-profile')
        ->assertVisible('@settings-nav-signatures')
        ->assertVisible('@settings-nav-mcp')
        ->assertMissing('@settings-nav-channels')
        ->assertMissing('@settings-nav-general')
        ->assertMissing('@settings-nav-webhooks')
        ->assertNoJavaScriptErrors();
});

test('the back link returns to the app', function () {
    $this->actingAs(settingsSidebarUser('admin'));

    $page = visit(route('app.labels.index'));
    waitForSettingsSidebarTestId($page, 'settings-back');
    $page->click('@settings-back');
    waitForSettingsSidebarTestId($page, 'sidebar-new');

    $page->assertVisible('@sidebar-new')->assertNoJavaScriptErrors();
});

test('with the sidebar collapsed the settings nav stays as icons and the footer toggle expands it', function () {
    $this->actingAs(settingsSidebarUser('admin'));

    $state = "document.querySelector('[data-slot=\"sidebar\"][data-state]')?.dataset.state";
    $layout = <<<'JS'
        (() => {
            const profile = document.querySelector('[data-testid="settings-nav-profile"]');
            const box = profile.getBoundingClientRect();
            return {
                state: document.querySelector('[data-slot="sidebar"][data-state]').dataset.state,
                width: Math.round(box.width),
                active: profile.dataset.active,
                icon: profile.querySelector('svg').getBoundingClientRect().width > 0,
                back: document.querySelector('[data-testid="settings-back"]').getBoundingClientRect().width > 0,
                trigger: (document.querySelector('[data-testid="app-sidebar-trigger"]')?.getBoundingClientRect().width ?? 0) > 0,
            };
        })()
    JS;

    $page = visit(route('app.profile.edit'))->resize(1280, 900);
    waitForSettingsSidebarTestId($page, 'sidebar-footer-toggle');
    $page->click('@sidebar-footer-toggle');
    waitForSettingsSidebarScript($page, "{$state} === 'collapsed'");
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    expect($page->script($layout))->toBe([
        'state' => 'collapsed',
        'width' => 32,
        'active' => 'true',
        'icon' => true,
        'back' => true,
        'trigger' => false,
    ]);
    $page->assertMissing('@settings-nav-channels-count');

    $page->script('location.reload()');
    waitForSettingsSidebarTestId($page, 'settings-nav-profile');
    waitForSettingsSidebarScript($page, "{$state} === 'collapsed'");
    $page->assertVisible('@settings-nav-profile');

    $page->click('@sidebar-footer-toggle');
    waitForSettingsSidebarScript($page, "{$state} === 'expanded'");
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    $expanded = $page->script($layout);

    expect($expanded['state'])->toBe('expanded')
        ->and($expanded['width'])->toBeGreaterThan(150)
        ->and($expanded['active'])->toBe('true')
        ->and($expanded['trigger'])->toBeFalse();

    $page->assertSeeIn('@settings-nav-profile', 'Profile')
        ->assertNoJavaScriptErrors();
});

function waitForSettingsSidebarScript(mixed $page, string $condition): void
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

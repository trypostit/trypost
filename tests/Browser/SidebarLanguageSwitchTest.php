<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\User\Theme;
use App\Models\User;
use App\Models\Workspace;

test('switching language in the sidebar translates the page in place', function () {
    $user = User::factory()->create(['locale' => Locale::English]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));
    waitFor($page, '@sidebar-workspace-menu');

    // A full page load would clear this, so it doubles as the no-reload assertion.
    $page->script('window.__notReloaded = true;');

    // The submenu is a Radix sub-trigger; it needs a pointer event before the
    // click, and Playwright's click alone does not open it.
    $page->script(<<<'JS'
        (async () => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));
            document.querySelector('[data-testid="sidebar-workspace-menu"]').click();
            await wait(400);
            const trigger = document.querySelector('[data-testid="sidebar-language-trigger"]');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
            await wait(600);
            document.querySelector('[data-testid="sidebar-language-ja"]').click();
        })();
    JS);

    $japanese = __('sidebar.groups.posts', [], 'ja');

    waitForCondition($page, "document.body.innerText.includes('{$japanese}')");

    $page->assertNoJavaScriptErrors();
    $page->assertSee($japanese)
        ->assertDontSee(__('sidebar.groups.posts', [], 'en'))
        ->assertScript('window.__notReloaded === true', true)
        ->assertNoJavaScriptErrors();

    expect($user->refresh()->locale)->toBe(Locale::Japanese);
});

test('the calendar header follows the language, not the previous one', function () {
    $user = User::factory()->create(['locale' => Locale::English]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $week = now()->startOfMonth()->addDays(10)->startOfWeek();
    $page = visit(route('app.calendar', ['view' => 'week', 'week' => $week->toDateString()]));
    waitFor($page, '@sidebar-workspace-menu');

    $page->script(<<<'JS'
        (async () => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));
            document.querySelector('[data-testid="sidebar-workspace-menu"]').click();
            await wait(400);
            const trigger = document.querySelector('[data-testid="sidebar-language-trigger"]');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
            await wait(600);
            document.querySelector('[data-testid="sidebar-language-pt-BR"]').click();
            await wait(2500);
            window.__header = document.body.innerText.match(/\d+[–-]\d+ [^\n]*/)?.[0] ?? '';
        })();
    JS);

    waitForCondition($page, 'window.__header !== undefined');

    // The month name comes from dayjs on the client, so it is the piece that used
    // to lag one switch behind the interface strings.
    $page->assertScript("/{$week->locale('pt_BR')->translatedFormat('F')}/i.test(window.__header)", true)
        ->assertScript("/{$week->format('F')}/.test(window.__header)", false);
});

test('switching theme in the sidebar applies it in place and saves it', function () {
    $user = User::factory()->create(['theme' => Theme::Light]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));
    waitFor($page, '@sidebar-workspace-menu');

    $page->script('window.__notReloaded = true;');

    $pick = fn (string $theme) => $page->script(<<<JS
        (async () => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));
            document.querySelector('[data-testid="sidebar-workspace-menu"]').click();
            await wait(400);
            const trigger = document.querySelector('[data-testid="sidebar-theme-trigger"]');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
            await wait(600);
            document.querySelector('[data-testid="sidebar-theme-{$theme}"]').click();
            await wait(600);
        })();
    JS);

    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeFalse();

    $pick('dark');

    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeTrue();

    waitForDatabase($page, fn () => $user->refresh()->theme === Theme::Dark);

    expect($user->theme)->toBe(Theme::Dark);

    $pick('light');

    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeFalse();

    waitForDatabase($page, fn () => $user->refresh()->theme === Theme::Light);

    expect($user->theme)->toBe(Theme::Light);

    $page->assertScript('window.__notReloaded === true', true)
        ->assertNoJavaScriptErrors();
});

test('the user menu has a help and support submenu with the support links', function (bool $selfHosted) {
    config(['trypost.self_hosted' => $selfHosted]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.calendar'));
    waitFor($page, '@sidebar-workspace-menu');

    $links = $page->script(<<<'JS'
        (async () => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));
            document.querySelector('[data-testid="sidebar-workspace-menu"]').click();
            await wait(400);
            const trigger = document.querySelector('[data-testid="sidebar-support-trigger"]');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
            await wait(600);
            return {
                links: ['help-center', 'discord', 'feature-requests', 'github']
                    .map((key) => document.querySelector(`[data-testid="sidebar-support-${key}"]`)?.getAttribute('href')),
                chat: document.querySelector('[data-testid="sidebar-support-chat"]') !== null,
                status: document.querySelector('[data-testid="sidebar-support-status"] iframe')?.getAttribute('src') ?? null,
                titles: ['help', 'community', 'status']
                    .filter((key) => document.querySelector(`[data-testid="sidebar-support-${key}-title"]`) !== null),
            };
        })();
    JS);

    expect($links['titles'])->toBe($selfHosted ? ['help', 'community'] : ['help', 'community', 'status'])
        ->and($links['chat'])->toBe(! $selfHosted)
        ->and($links['status'])->toBe($selfHosted ? null : 'https://status.trypost.it/badge?theme=light')
        ->and($links['links'])->toBe([
            'https://docs.trypost.it',
            'https://trypost.it/discord',
            'https://github.com/orgs/trypostit/discussions/categories/feature-requests',
            'https://github.com/trypostit',
        ]);

    $page->assertNoJavaScriptErrors();
})->with(['cloud' => false]);

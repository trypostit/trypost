<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\User\Theme;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForSidebarLanguageTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 150; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

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
    waitForSidebarLanguageTestId($page, 'sidebar-workspace-menu');

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

    $page->script("(async () => { for (let i = 0; i < 150; i++) { if (document.body.innerText.includes('{$japanese}')) return; await new Promise((r) => setTimeout(r, 50)); } })();");

    $page->assertNoJavaScriptErrors();
    $page->assertSee($japanese)
        ->assertDontSee(__('sidebar.groups.posts', [], 'en'))
        ->assertScript('window.__notReloaded === true', true)
        ->assertNoJavaScriptErrors();

    expect($user->refresh()->locale)->toBe(Locale::Japanese);
});

test('switching to a right-to-left language flips the document direction', function () {
    $user = User::factory()->create(['locale' => Locale::English]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));
    waitForSidebarLanguageTestId($page, 'sidebar-workspace-menu');

    $page->script(<<<'JS'
        (async () => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));
            window.__dirBefore = document.documentElement.dir;
            document.querySelector('[data-testid="sidebar-workspace-menu"]').click();
            await wait(400);
            const trigger = document.querySelector('[data-testid="sidebar-language-trigger"]');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
            await wait(600);
            document.querySelector('[data-testid="sidebar-language-ar"]').click();
            for (let i = 0; i < 150; i++) {
                if (document.documentElement.dir === 'rtl') return;
                await wait(50);
            }
        })();
    JS);

    $page->assertScript('window.__dirBefore', 'ltr')
        ->assertScript('document.documentElement.dir', 'rtl')
        ->assertNoJavaScriptErrors();

    expect($user->refresh()->locale)->toBe(Locale::Arabic);
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

    $page = visit(route('app.calendar', ['view' => 'week', 'week' => '2026-09-14']));
    waitForSidebarLanguageTestId($page, 'sidebar-workspace-menu');

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

    $page->script('(async () => { for (let i = 0; i < 150; i++) { if (window.__header !== undefined) return; await new Promise((r) => setTimeout(r, 50)); } })();');

    // The month name comes from dayjs on the client, so it is the piece that used
    // to lag one switch behind the interface strings.
    $page->assertScript('/setembro/i.test(window.__header)', true)
        ->assertScript('/September/.test(window.__header)', false);
});

test('the month view header follows the language too', function () {
    $user = User::factory()->create(['locale' => Locale::English]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => '2026-09-01']));
    waitForSidebarLanguageTestId($page, 'sidebar-workspace-menu');

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
            window.__monthHeader = document.body.innerText;
        })();
    JS);

    $page->script('(async () => { for (let i = 0; i < 150; i++) { if (window.__monthHeader !== undefined) return; await new Promise((r) => setTimeout(r, 50)); } })();');

    $page->assertScript('/setembro/i.test(window.__monthHeader)', true)
        ->assertScript('/September/.test(window.__monthHeader)', false);
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
    waitForSidebarLanguageTestId($page, 'sidebar-workspace-menu');

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

    for ($attempt = 0; $attempt < 30 && $user->refresh()->theme !== Theme::Dark; $attempt++) {
        $page->script('new Promise((r) => setTimeout(r, 100))');
    }

    expect($user->theme)->toBe(Theme::Dark);

    $pick('light');

    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeFalse();

    for ($attempt = 0; $attempt < 30 && $user->refresh()->theme !== Theme::Light; $attempt++) {
        $page->script('new Promise((r) => setTimeout(r, 100))');
    }

    expect($user->theme)->toBe(Theme::Light);

    $page->assertScript('window.__notReloaded === true', true)
        ->assertNoJavaScriptErrors();
});

test('the sidebar menu header pluralizes the channel count in the user language', function (int $channels) {
    $user = User::factory()->create(['locale' => Locale::Polish]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    SocialAccount::factory()->count($channels)->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));
    waitForSidebarLanguageTestId($page, 'sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
    waitForSidebarLanguageTestId($page, 'sidebar-menu-plan');

    $page->assertSeeIn('@sidebar-menu-plan', trans_choice('sidebar.channels_count', $channels, [], 'pl'))
        ->assertSeeIn('@sidebar-menu-manage-team', __('sidebar.manage_team', [], 'pl'))
        ->assertNoJavaScriptErrors();
})->with([1, 3, 5]);

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
    waitForSidebarLanguageTestId($page, 'sidebar-workspace-menu');

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
})->with(['cloud' => false, 'self-hosted' => true]);

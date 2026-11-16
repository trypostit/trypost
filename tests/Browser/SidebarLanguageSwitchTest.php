<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\User\Theme;
use App\Models\User;
use App\Models\Workspace;

/**
 * Opens a submenu of the user menu. Each item is a Radix sub-trigger, which
 * opens on a pointer move, so the trigger is hovered rather than clicked.
 */
function openSidebarSubmenu(mixed $page, string $trigger, string $content): void
{
    waitFor($page, '@sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
    waitFor($page, $trigger);
    $page->hover($trigger);
    waitFor($page, $content);
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
    waitFor($page, '@sidebar-workspace-menu');

    // A full page load would clear this, so it doubles as the no-reload assertion.
    $page->script('window.__notReloaded = true;');

    openSidebarSubmenu($page, '@sidebar-language-trigger', '@sidebar-language-search');
    $page->click('@sidebar-language-option-ja');

    $japanese = __('sidebar.groups.posts', [], 'ja');

    waitForCondition($page, "document.body.innerText.includes('{$japanese}')");

    $page->assertNoJavaScriptErrors();
    $page->assertSee($japanese)
        ->assertDontSee(__('sidebar.groups.posts', [], 'en'))
        ->assertScript('window.__notReloaded === true', true)
        ->assertNoJavaScriptErrors();

    expect($user->refresh()->locale)->toBe(Locale::Japanese);
});

test('the sidebar language list scrolls and finds a language by search', function () {
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

    openSidebarSubmenu($page, '@sidebar-language-trigger', '@sidebar-language-search');

    $list = elementsMap($page, '@sidebar-language-list', '({ scrolls: element.scrollHeight > element.clientHeight, fits: element.getBoundingClientRect().bottom <= window.innerHeight })');

    expect($list)->toBe([['scrolls' => true, 'fits' => true]]);

    $page->type('@sidebar-language-search', 'indo');

    waitForCount($page, '@sidebar-language-list button', 1);
    waitFor($page, '@sidebar-language-option-id');

    $page->click('@sidebar-language-option-id');

    waitForGone($page, '@sidebar-language-search');
    waitForDatabase($page, fn () => $user->refresh()->locale === Locale::Indonesian);

    expect($user->locale)->toBe(Locale::Indonesian);

    $page->assertNoJavaScriptErrors();
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

    openSidebarSubmenu($page, '@sidebar-language-trigger', '@sidebar-language-search');
    $page->click('@sidebar-language-option-pt-BR');

    waitForDatabase($page, fn () => $user->refresh()->locale === Locale::PortugueseBrazil);

    // The month name comes from dayjs on the client, so it is the piece that used
    // to lag one switch behind the interface strings.
    waitForText($page, '@calendar-title', $week->locale('pt_BR')->translatedFormat('F'));

    expect(elementsMap($page, '@calendar-title', 'element.textContent'))
        ->each->not->toContain($week->format('F'));

    $page->assertNoJavaScriptErrors();
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

    $pick = function (string $theme) use ($page): void {
        openSidebarSubmenu($page, '@sidebar-theme-trigger', "@sidebar-theme-{$theme}");
        $page->click("@sidebar-theme-{$theme}");
        waitForGone($page, '@sidebar-theme-trigger');
    };

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

    openSidebarSubmenu($page, '@sidebar-support-trigger', '@sidebar-support-help-center');

    $links = [
        'links' => array_merge(...array_map(
            fn (string $key) => elementsMap($page, "@sidebar-support-{$key}", "element.getAttribute('href')"),
            ['help-center', 'discord', 'feature-requests', 'github'],
        )),
        'chat' => elementsMap($page, '@sidebar-support-chat', 'true') !== [],
        'status' => elementsMap($page, '@sidebar-support-status iframe', "element.getAttribute('src')")[0] ?? null,
        'titles' => array_values(array_filter(
            ['help', 'community', 'status'],
            fn (string $key) => elementsMap($page, "@sidebar-support-{$key}-title", 'true') !== [],
        )),
    ];

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

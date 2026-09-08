<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\UserWorkspace\Role;
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
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
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

    $japanese = __('sidebar.posts.all', [], 'ja');

    $page->script("(async () => { for (let i = 0; i < 150; i++) { if (document.body.innerText.includes('{$japanese}')) return; await new Promise((r) => setTimeout(r, 50)); } })();");

    $page->assertSee($japanese)
        ->assertDontSee(__('sidebar.posts.all', [], 'en'))
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
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
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
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));
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

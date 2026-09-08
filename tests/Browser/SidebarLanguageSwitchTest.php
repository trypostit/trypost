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

test('switching language in the sidebar translates the app without a reload', function () {
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

    // Drives the same request the sidebar switcher issues. The submenu itself is
    // a Radix sub-trigger that Playwright cannot open reliably.
    $page->script(<<<'JS'
        (async () => {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            await fetch('/settings/language', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ _method: 'PUT', locale: 'ja' }),
            });
            window.__switched = true;
        })();
    JS);

    $page->script('(async () => { for (let i = 0; i < 150; i++) { if (window.__switched) return; await new Promise((r) => setTimeout(r, 50)); } })();');

    // An Inertia visit, not a page load — this is what has to re-apply the locale.
    $page->click('@nav-/posts');

    $page->script('(async () => { for (let i = 0; i < 150; i++) { if (document.body.innerText.includes("'.__('sidebar.posts.all', [], 'ja').'")) return; await new Promise((r) => setTimeout(r, 50)); } })();');

    $page->assertSee(__('sidebar.posts.all', [], 'ja'))
        ->assertScript('window.__notReloaded === true', true)
        ->assertNoJavaScriptErrors();

    expect($user->refresh()->locale)->toBe(Locale::Japanese);
});

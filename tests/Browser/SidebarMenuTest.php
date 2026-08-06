<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('the unified sidebar menu exposes account and workspace actions', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    subscribeAccount($user->account);

    $this->actingAs($user);

    $page = visit(route('app.calendar'));

    $page->script(<<<'JS'
        (async () => {
            const sel = '[data-testid="sidebar-workspace-menu"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    $page->assertVisible('@sidebar-workspace-menu')
        ->click('@sidebar-workspace-menu');

    $page->script(<<<'JS'
        (async () => {
            const sel = '[data-testid="logout-button"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    $page->assertVisible('@sidebar-menu-my-account')
        ->assertVisible('@sidebar-menu-workspace-settings')
        ->assertVisible('@logout-button');
});

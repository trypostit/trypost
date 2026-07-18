<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Automation;
use App\Models\User;
use App\Models\Workspace;

test('the automation builder shows a desktop-recommended notice on a phone', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $automation = Automation::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.automations.workflow', $automation))->resize(375, 812);

    $page->script(<<<'JS'
        (async () => {
            const sel = '[data-testid="automation-mobile-notice"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    $page->assertVisible('@automation-mobile-notice');

    // The desktop-only builder header (name, status, guide, save, tabs) is
    // replaced by a minimal back-only header on a phone — nothing here is
    // actionable, so only the back button and the floating hamburger remain.
    $page->assertVisible('@automation-mobile-back');
});

test('the automation builder shows the full header on desktop, not the minimal one', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $automation = Automation::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.automations.workflow', $automation))->resize(1280, 900);

    $page->assertMissing('@automation-mobile-back');
    $page->assertMissing('@automation-mobile-notice');
});

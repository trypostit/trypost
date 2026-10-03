<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

function waitForWorkspaceCreateTestId(mixed $page, string $testId): void
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

test('a workspace is created from its name alone and settings have no brand page', function () {
    config(['trypost.self_hosted' => true]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.workspaces.create'));
    waitForWorkspaceCreateTestId($page, 'workspaces-create-name');

    $page->fill('@workspaces-create-name', 'Second')
        ->click('@workspaces-create-submit')
        ->assertPathIs(parse_url(route('app.workspace.channels'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();

    expect(Workspace::where('name', 'Second')->exists())->toBeTrue();

    $settings = visit(route('app.workspace.settings'));
    waitForWorkspaceCreateTestId($settings, 'settings-sidebar');

    $settings->assertVisible('@settings-nav-general')
        ->assertMissing('@settings-nav-brand')
        ->assertNoJavaScriptErrors();
});

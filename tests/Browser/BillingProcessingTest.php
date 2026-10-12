<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

test('once the subscription and plan are ready the processing page sends the user to the MCP step', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $user->account->update(['plan_id' => Plan::query()->value('id') ?? Plan::factory()->create()->id]);

    $this->actingAs($user);

    $mcpPath = parse_url(route('app.welcome.mcp'), PHP_URL_PATH);

    $page = visit(route('app.billing.processing'));
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if (window.location.pathname === '{$mcpPath}') return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertPathIs($mcpPath)
        ->assertNoJavaScriptErrors();
});

test('while checkout settles the processing page uses the neutral auth layout, not the welcome one', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.billing.processing'));
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="billing-processing"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertSee('Processing your subscription')
        ->assertVisible('@billing-processing')
        ->assertVisible('@auth-logo')
        ->assertMissing('@welcome-back')
        ->assertMissing('@welcome-step-plan')
        ->assertNoJavaScriptErrors();
});

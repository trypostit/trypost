<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('creating a webhook opens a full-height right-side sheet that can be dismissed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route('app.webhooks.index'));

    $page->click('@create-webhook-button');

    $layout = $page->script(<<<'JS'
        (async () => {
            let sheet;
            for (let attempt = 0; attempt < 100; attempt++) {
                sheet = document.querySelector('[data-testid="create-webhook-sheet"]');
                const rect = sheet?.getBoundingClientRect();
                if (rect && Math.abs(rect.right - window.innerWidth) < 2) break;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            const rect = sheet.getBoundingClientRect();

            return {
                leftOfCenter: rect.left > window.innerWidth / 2,
                rightAligned: Math.abs(rect.right - window.innerWidth) < 2,
                fullHeight: Math.abs(rect.height - window.innerHeight) < 2,
            };
        })();
    JS);

    expect($layout)
        ->leftOfCenter->toBeTrue()
        ->rightAligned->toBeTrue()
        ->fullHeight->toBeTrue();

    $page->assertVisible('@create-webhook-endpoint')
        ->assertVisible('@create-webhook-events')
        ->click('@cancel-create-webhook')
        ->assertMissing('@create-webhook-sheet')
        ->assertNoJavaScriptErrors();
});

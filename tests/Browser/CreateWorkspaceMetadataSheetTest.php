<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('workspace metadata creation uses a right-side sheet and still saves', function (string $resource, string $routeName, string $table) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route($routeName));
    $page->click("@create-{$resource}-button");

    $layout = $page->script(<<<JS
        (async () => {
            let sheet;
            for (let attempt = 0; attempt < 100; attempt++) {
                sheet = document.querySelector('[data-testid="create-{$resource}-sheet"]');
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

    $name = "New {$resource}";
    $page->fill("@create-{$resource}-name", $name);

    if ($resource === 'signature') {
        $page->fill('@create-signature-content', 'Saved from the slide-over');
    }

    $page->click("@submit-create-{$resource}");
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (! document.querySelector('[data-testid="create-{$resource}-sheet"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $this->assertDatabaseHas($table, [
        'workspace_id' => $workspace->id,
        'name' => $name,
    ]);

    $page->click("@create-{$resource}-button")
        ->assertVisible("@create-{$resource}-sheet")
        ->click("@cancel-create-{$resource}")
        ->assertMissing("@create-{$resource}-sheet")
        ->assertNoJavaScriptErrors();
})->with([
    'signature' => ['signature', 'app.signatures.index', 'workspace_signatures'],
    'label' => ['label', 'app.labels.index', 'workspace_labels'],
]);

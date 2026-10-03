<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

test('workspace metadata creation uses a centered dialog and still saves', function (string $resource, string $routeName, string $table) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route($routeName));
    $page->click("@create-{$resource}-button");

    $layout = $page->script(<<<JS
        (async () => {
            let sheet;
            for (let attempt = 0; attempt < 100 && ! sheet; attempt++) {
                sheet = document.querySelector('[data-testid="create-{$resource}-sheet"]');
                if (! sheet) await new Promise((resolve) => setTimeout(resolve, 50));
            }
            await Promise.all(sheet.getAnimations().map((animation) => animation.finished));

            const rect = sheet.getBoundingClientRect();
            const cancel = sheet.querySelector('[data-testid="cancel-create-{$resource}"]');
            const action = sheet.querySelector('[data-testid="submit-create-{$resource}"]');

            return {
                centered: Math.abs(rect.left + rect.width / 2 - window.innerWidth / 2) < 2
                    && Math.abs(rect.top + rect.height / 2 - window.innerHeight / 2) < 2,
                shorterThanViewport: rect.height < window.innerHeight,
                cancelBeforeAction: cancel.getBoundingClientRect().right <= action.getBoundingClientRect().left,
            };
        })();
    JS);

    expect($layout)
        ->centered->toBeTrue()
        ->shorterThanViewport->toBeTrue()
        ->cancelBeforeAction->toBeTrue();

    $name = "New {$resource}";
    $page->fill("@create-{$resource}-name", $name);

    if ($resource === 'signature') {
        $page->fill('@create-signature-content', 'Saved from the dialog');
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

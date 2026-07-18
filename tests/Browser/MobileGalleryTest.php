<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;

test('gallery upload card actions are visible on a phone without hover', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    Media::factory()->for($workspace, 'mediable')->create([
        'collection' => 'assets',
        'original_filename' => 'on-the-go.jpg',
    ]);

    $this->actingAs($user);

    $page = visit(route('app.assets.index'))->resize(375, 812);

    // Uploads are fetched async after mount; settle before asserting.
    $page->script(<<<'JS'
        (async () => {
            const sel = '[data-testid="gallery-asset-delete"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    $page->assertVisible('@gallery-asset-delete');
});

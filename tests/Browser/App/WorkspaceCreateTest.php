<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

test('a workspace is created from its name alone', function () {
    config(['trypost.self_hosted' => true]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.workspaces.create'));
    waitFor($page, '@workspaces-create-name');

    $page->fill('@workspaces-create-name', 'Second')
        ->click('@workspaces-create-submit')
        ->assertPathIs(parse_url(route('app.posts.index'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();

    expect(Workspace::where('name', 'Second')->exists())->toBeTrue();
});

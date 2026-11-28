<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

test('back on the workspaces list returns to the app', function () {
    config(['trypost.self_hosted' => true]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.workspaces.index'));
    waitFor($page, '@workspaces-back');

    $page->click('@workspaces-back');
    waitForPath($page, parse_url(route('app.calendar'), PHP_URL_PATH));

    $page->assertPathIs(parse_url(route('app.calendar'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
});

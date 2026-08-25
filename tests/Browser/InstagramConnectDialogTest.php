<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

/**
 * The picker emits an already-resolved connect method, so it must bypass the
 * picker branch. Wiring it back through startConnect() reopened the dialog and
 * made the standalone Instagram flow unreachable.
 */
function instagramConnectOwner(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('choosing standalone instagram closes the picker instead of reopening it', function () {
    $this->actingAs(instagramConnectOwner());

    $page = visit(route('app.accounts'));

    $page->click('@connect-instagram')
        ->assertVisible('@instagram-connect-dialog')
        ->click('@instagram-connect-standalone')
        ->assertMissing('@instagram-connect-dialog')
        ->assertNoJavaScriptErrors();
});

test('choosing instagram via facebook also closes the picker', function () {
    $this->actingAs(instagramConnectOwner());

    $page = visit(route('app.accounts'));

    $page->click('@connect-instagram')
        ->assertVisible('@instagram-connect-dialog')
        ->click('@instagram-connect-facebook')
        ->assertMissing('@instagram-connect-dialog')
        ->assertNoJavaScriptErrors();
});

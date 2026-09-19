<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('the app renders in the user locale right after logging in', function () {
    $user = User::factory()->create(['locale' => Locale::Japanese]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $page = visit(route('login'));

    $page->fill('email', $user->email);
    $page->fill('password', 'password');
    $page->click('@login-submit');

    $page->script('(async () => { for (let i = 0; i < 150; i++) { if (location.pathname !== "/login") return; await new Promise((r) => setTimeout(r, 50)); } })();');

    $page->assertSee(__('sidebar.posts.all', [], 'ja'))
        ->assertDontSee(__('sidebar.posts.all', [], 'en'));
});

<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

function backofficeTestUser(string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $user->account->forceFill(['trial_ends_at' => now()->addWeek()])->save();
    connectAiTo($user);

    return $user->fresh();
}

test('an admin opens the backoffice from the user menu and gets its own sidebar', function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $this->actingAs(backofficeTestUser('admin@trypost.test'));

    $page = visit(route('app.calendar'));

    waitFor($page, '@sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
    waitFor($page, '@sidebar-menu-backoffice');
    $page->click('@sidebar-menu-backoffice');

    waitForPath($page, '/admin');
    waitFor($page, '@admin-sidebar');

    $page->assertVisible('@admin-nav-dashboard')
        ->assertVisible('@admin-nav-users')
        ->assertVisible('@admin-back-to-app')
        ->assertMissing('@sidebar-new')
        ->assertNoJavaScriptErrors();

    $page->click('@admin-back-to-app');
    waitFor($page, '@sidebar-new');
    $page->assertNoJavaScriptErrors();
});

test('a user who is not an admin has no backoffice item in the user menu', function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $this->actingAs(backofficeTestUser('someone@trypost.test'));

    $page = visit(route('app.calendar'));

    waitFor($page, '@sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
    waitFor($page, '@logout-button');

    $page->assertMissing('@sidebar-menu-backoffice')
        ->assertNoJavaScriptErrors();
});

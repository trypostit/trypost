<?php

declare(strict_types=1);

test('an admin opens the backoffice from the user menu and gets its own sidebar', function () {
    $admin = workspaceOwner();
    config()->set('trypost.admin.emails', [$admin->email]);
    $admin->account->forceFill(['trial_ends_at' => now()->addWeek()])->save();
    connectAiTo($admin);
    $this->actingAs($admin);

    $page = visit(route('app.calendar'));

    waitFor($page, '@sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
    waitFor($page, '@sidebar-menu-backoffice');
    $page->click('@sidebar-menu-backoffice');

    waitForPath($page, route('admin.dashboard', absolute: false));
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
    backofficeAdmin();
    $user = workspaceOwner();
    $user->account->forceFill(['trial_ends_at' => now()->addWeek()])->save();
    connectAiTo($user);
    $this->actingAs($user);

    $page = visit(route('app.calendar'));

    waitFor($page, '@sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
    waitFor($page, '@logout-button');

    $page->assertMissing('@sidebar-menu-backoffice')
        ->assertNoJavaScriptErrors();
});

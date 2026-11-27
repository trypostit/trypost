<?php

declare(strict_types=1);

test('an admin views the app as a user and stops from the banner', function () {
    $admin = backofficeAdmin();
    $user = workspaceOwner(['name' => 'Grace', 'email' => 'grace@example.test']);
    connectAiTo($user);

    $this->actingAs($admin);

    $page = visit(route('admin.users.show', $user));

    waitFor($page, '@admin-impersonate-user');
    $page->click('@admin-impersonate-user');

    waitFor($page, '@impersonation-banner');
    $page->assertSeeIn('@impersonation-banner', 'grace@example.test')
        ->assertNoJavaScriptErrors();

    $page->click('@impersonation-stop');

    waitFor($page, '@admin-user');
    $page->assertPathIs(route('admin.users.show', $user, absolute: false))
        ->assertMissing('@impersonation-banner')
        ->assertNoJavaScriptErrors();
});

test('an admin viewing as a user without a plan can stop from the welcome steps', function () {
    $admin = backofficeAdmin();
    $user = workspaceOwner(['name' => 'No Plan', 'email' => 'noplan@example.test']);

    $this->actingAs($admin);

    $page = visit(route('admin.users.show', $user));

    waitFor($page, '@admin-impersonate-user');
    $page->click('@admin-impersonate-user');

    waitFor($page, '@impersonation-banner');
    $page->assertPathBeginsWith(route('app.welcome', absolute: false))
        ->assertSeeIn('@impersonation-banner', 'noplan@example.test');

    $page->click('@impersonation-stop');

    waitFor($page, '@admin-user');
    $page->assertMissing('@impersonation-banner')->assertNoJavaScriptErrors();
});

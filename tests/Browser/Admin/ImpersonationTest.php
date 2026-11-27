<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

test('an admin views the app as a user and stops from the banner', function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $admin = User::factory()->create(['email' => 'admin@trypost.test']);
    $user = User::factory()->create(['name' => 'Grace', 'email' => 'grace@example.test']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    connectAiTo($user->fresh());

    $this->actingAs($admin);

    $page = visit(route('admin.users.show', $user));

    waitFor($page, '@admin-impersonate-user');
    $page->click('@admin-impersonate-user');

    waitFor($page, '@impersonation-banner');
    $page->assertSeeIn('@impersonation-banner', 'grace@example.test')
        ->assertNoJavaScriptErrors();

    $page->click('@impersonation-stop');

    waitFor($page, '@admin-user');
    $page->assertPathIs("/admin/users/{$user->id}")
        ->assertMissing('@impersonation-banner')
        ->assertNoJavaScriptErrors();
});

test('an admin viewing as a user without a plan can stop from the welcome steps', function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $admin = User::factory()->create(['email' => 'admin@trypost.test']);
    $user = User::factory()->create(['name' => 'No Plan', 'email' => 'noplan@example.test']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($admin);

    $page = visit(route('admin.users.show', $user));

    waitFor($page, '@admin-impersonate-user');
    $page->click('@admin-impersonate-user');

    waitFor($page, '@impersonation-banner');
    $page->assertPathBeginsWith('/welcome')
        ->assertSeeIn('@impersonation-banner', 'noplan@example.test');

    $page->click('@impersonation-stop');

    waitFor($page, '@admin-user');
    $page->assertMissing('@impersonation-banner')->assertNoJavaScriptErrors();
});

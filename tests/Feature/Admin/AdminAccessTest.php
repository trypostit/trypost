<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
});

test('a guest is sent to login from the admin', function () {
    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('login'));
});

test('a user not in ADMIN_EMAILS is forbidden', function () {
    $user = User::factory()->create(['email' => 'someone@trypost.test']);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('an admin in ADMIN_EMAILS gets in whether or not the email is verified', function () {
    $admin = backofficeAdmin(['email_verified_at' => null]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk();
});

test('an admin in ADMIN_EMAILS sees the dashboard, whatever the case of the email', function () {
    $admin = User::factory()->create(['email' => 'Admin@TryPost.test']);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/Index'));
});

test('an empty ADMIN_EMAILS closes the admin to everyone', function () {
    config()->set('trypost.admin.emails', []);
    $user = User::factory()->create(['email' => 'admin@trypost.test']);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();
});

test('horizon follows ADMIN_EMAILS', function () {
    $admin = backofficeAdmin();
    $user = User::factory()->create(['email' => 'someone@trypost.test']);

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

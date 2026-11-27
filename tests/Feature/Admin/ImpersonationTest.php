<?php

declare(strict_types=1);

use App\Enums\Activity\LogName;
use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use App\Support\Admin\Impersonation;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $this->admin = User::factory()->create(['email' => 'admin@trypost.test', 'name' => 'Admin']);
});

function impersonationTarget(array $attributes = []): User
{
    $user = User::factory()->create(['name' => 'Grace', ...$attributes]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('an admin impersonates a user and lands in the app as them', function () {
    $user = impersonationTarget();

    $this->actingAs($this->admin)
        ->post(route('admin.users.impersonate', $user))
        ->assertRedirect(route('app.calendar'));

    $this->assertAuthenticatedAs($user);
    expect(session(Impersonation::SESSION_KEY))->toBe($this->admin->id)
        ->and(Activity::query()->inLog(LogName::Admin)->sole())
        ->event->toBe('impersonation.started');
});

test('the app shares who is being impersonated', function () {
    $user = impersonationTarget(['email' => 'grace@example.test']);
    $user->account->forceFill(['trial_ends_at' => now()->addWeek()])->save();

    $this->actingAs($user)
        ->withSession([Impersonation::SESSION_KEY => $this->admin->id])
        ->get(route('app.calendar'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('impersonating.name', 'Grace')
            ->where('impersonating.email', 'grace@example.test'),
        );
});

test('stopping signs the admin back in and returns to the user page', function () {
    $user = impersonationTarget();

    $this->actingAs($user)
        ->withSession([Impersonation::SESSION_KEY => $this->admin->id])
        ->delete(route('app.impersonation.destroy'))
        ->assertRedirect(route('admin.users.show', $user));

    $this->assertAuthenticatedAs($this->admin);
    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();

    $stopped = Activity::query()->where('event', 'impersonation.stopped')->sole();
    expect($stopped->causer->is($this->admin))->toBeTrue()
        ->and($stopped->subject->is($user))->toBeTrue();
});

test('stopping works after the admin left ADMIN_EMAILS and gives no admin access', function () {
    $user = impersonationTarget();
    config()->set('trypost.admin.emails', []);

    $this->actingAs($user)
        ->withSession([Impersonation::SESSION_KEY => $this->admin->id])
        ->delete(route('app.impersonation.destroy'))
        ->assertRedirect(route('app.calendar'));

    $this->assertAuthenticatedAs($this->admin);
    $this->get(route('admin.dashboard'))->assertForbidden();
});

test('an admin cannot impersonate another admin, a blocked user or themselves', function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test', 'other@trypost.test']);
    $otherAdmin = User::factory()->create(['email' => 'other@trypost.test']);
    $blocked = User::factory()->blocked()->create();

    foreach ([$otherAdmin, $blocked, $this->admin] as $target) {
        $this->actingAs($this->admin)
            ->post(route('admin.users.impersonate', $target))
            ->assertSessionHasErrors('user');

        $this->assertAuthenticatedAs($this->admin);
    }
});

test('a user who is not impersonating cannot stop', function () {
    $user = impersonationTarget();

    $this->actingAs($user)
        ->delete(route('app.impersonation.destroy'))
        ->assertNotFound();
});

test('nothing is sent to PostHog while impersonating', function () {
    config()->set('services.posthog.enabled', true);
    config()->set('services.posthog.api_key', 'phc_test');

    expect(PostHogService::isEnabled())->toBeTrue();

    session()->put(Impersonation::SESSION_KEY, $this->admin->id);

    expect(PostHogService::isEnabled())->toBeFalse()
        ->and(PostHogService::shouldTrack())->toBeFalse();
});

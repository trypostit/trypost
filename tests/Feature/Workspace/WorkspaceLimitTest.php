<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
});

/**
 * User::factory() does not create a workspace — only CreateUser does.
 * Insert one so Socials / legacy start already at their cap of 1.
 */
$onPlan = function (Slug $slug): User {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::where('slug', $slug)->value('id')]);

    Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    // The controller guard checks hasActiveSubscription() *before* the cap.
    // Without this, GET at the Socials cap still renders, but store flashes
    // subscription_required instead of limit_reached.
    subscribeAccount($user->account);

    return $user->fresh();
};

test('an account with no plan can create its first workspace', function () {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => null]);

    expect($user->account->workspaces()->count())->toBe(0)
        ->and($user->fresh()->account->canCreateWorkspace())->toBeTrue();
});

test('an account with no plan cannot create a second workspace', function () {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => null]);

    Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    expect($user->fresh()->account->canCreateWorkspace())->toBeFalse();
});

test('the socials plan blocks a second workspace', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);

    expect($user->account->workspaces()->count())->toBe(1)
        ->and($user->account->canCreateWorkspace())->toBeFalse();
});

test('the workspaces plan allows more workspaces', function () use ($onPlan) {
    $user = $onPlan(Slug::Workspaces);

    Workspace::factory()->count(3)->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    expect($user->account->canCreateWorkspace())->toBeTrue();
});

test('the legacy plan is capped at one workspace', function () use ($onPlan) {
    $user = $onPlan(Slug::Workspace);

    expect($user->account->canCreateWorkspace())->toBeFalse();
});

test('self-hosted ignores the cap', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);

    config(['trypost.self_hosted' => true]);

    expect($user->account->canCreateWorkspace())->toBeTrue();
});

test('the create form is available when the plan is at its cap', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->get(route('app.workspaces.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/Create', false)
        );
});

test('a direct store POST cannot exceed the cap', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->post(route('app.workspaces.store'), ['name' => 'Second'])
        ->assertRedirect(route('app.workspaces.create'))
        ->assertSessionHas('flash.error', __('workspaces.limit_reached'));

    expect($user->account->workspaces()->count())->toBe(1);
});

test('the workspaces plan can store another workspace', function () use ($onPlan) {
    $user = $onPlan(Slug::Workspaces);

    $this->actingAs($user)
        ->post(route('app.workspaces.store'), ['name' => 'Second'])
        ->assertRedirect(route('app.accounts'));

    expect($user->account->workspaces()->count())->toBe(2);
});

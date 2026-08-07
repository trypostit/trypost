<?php

declare(strict_types=1);

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    subscribeAccount($this->user->account);
});

test('shares the onboarding residual progress for subscribed accounts', function () {
    $this->actingAs($this->user)
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('onboardingResidual.completed', 0)
            ->where('onboardingResidual.total', ResolveOnboardingStatus::TOTAL_STEPS)
        );
});

test('does not share the onboarding residual state after dismissal', function () {
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingResidual', false));
});

test('does not share the onboarding residual state after completion', function () {
    $this->user->account->forceFill(['onboarding_completed_at' => now()])->save();

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingResidual', false));
});

test('does not share the onboarding residual with workspace members', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, [
        'role' => Role::Member->value,
    ]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingResidual', false));
});

test('does not share the onboarding residual in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $this->actingAs($this->user)
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('onboardingResidual', false));
});

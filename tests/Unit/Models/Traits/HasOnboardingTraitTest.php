<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

it('tracks finished onboarding from completed or dismissed timestamps', function () {
    $open = Account::factory()->create([
        'onboarding_completed_at' => null,
        'onboarding_dismissed_at' => null,
    ]);
    $completed = Account::factory()->create([
        'onboarding_completed_at' => now(),
        'onboarding_dismissed_at' => null,
    ]);
    $dismissed = Account::factory()->create([
        'onboarding_completed_at' => null,
        'onboarding_dismissed_at' => now(),
    ]);

    expect($open->isOnboardingOpen())->toBeTrue()
        ->and($open->hasFinishedOnboarding())->toBeFalse()
        ->and($open->isOnboardingCompleted())->toBeFalse()
        ->and($open->isOnboardingDismissed())->toBeFalse()
        ->and($completed->isOnboardingOpen())->toBeFalse()
        ->and($completed->hasFinishedOnboarding())->toBeTrue()
        ->and($completed->isOnboardingCompleted())->toBeTrue()
        ->and($dismissed->isOnboardingOpen())->toBeFalse()
        ->and($dismissed->hasFinishedOnboarding())->toBeTrue()
        ->and($dismissed->isOnboardingDismissed())->toBeTrue();
});

it('reads skipped onboarding steps', function () {
    $account = Account::factory()->create(['onboarding_skipped_steps' => null]);
    $skipped = Account::factory()->create(['onboarding_skipped_steps' => ['mcp', 'social']]);

    expect($account->skippedOnboardingSteps())->toBe([])
        ->and($account->hasSkippedOnboardingStep('mcp'))->toBeFalse()
        ->and($skipped->skippedOnboardingSteps())->toBe(['mcp', 'social'])
        ->and($skipped->hasSkippedOnboardingStep('mcp'))->toBeTrue()
        ->and($skipped->hasSkippedOnboardingStep('first_post'))->toBeFalse();
});

it('scopes accounts with open onboarding', function () {
    $open = Account::factory()->create([
        'onboarding_completed_at' => null,
        'onboarding_dismissed_at' => null,
    ]);
    $completed = Account::factory()->create(['onboarding_completed_at' => now()]);
    $dismissed = Account::factory()->create(['onboarding_dismissed_at' => now()]);

    $ids = Account::query()
        ->onboardingOpen()
        ->whereIn('id', [$open->id, $completed->id, $dismissed->id])
        ->pluck('id');

    expect($ids->all())->toBe([$open->id]);
});

it('detects a bound mcp grant that unlocks the checklist', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    AccessToken::withoutEvents(fn () => mcpAccessToken($user, mcpOauthClient(), $workspace));

    expect($user->account->hasOnboardingMcpConnection())->toBeTrue();
});

it('ignores unbound expired viewer or teammate mcp grants', function (string $case) {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);

    if ($case === 'unbound') {
        AccessToken::withoutEvents(fn () => mcpAccessToken($owner, mcpOauthClient()));
    }

    if ($case === 'expired') {
        $token = AccessToken::withoutEvents(fn () => mcpAccessToken($owner, mcpOauthClient(), $workspace));
        $token->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();
    }

    if ($case === 'viewer') {
        $viewer = User::factory()->create(['account_id' => $owner->account_id]);
        $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
        AccessToken::withoutEvents(fn () => mcpAccessToken($viewer, mcpOauthClient(), $workspace));
    }

    if ($case === 'teammate') {
        $member = User::factory()->create(['account_id' => $owner->account_id]);
        $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
        AccessToken::withoutEvents(fn () => mcpAccessToken($member, mcpOauthClient(), $workspace));
    }

    expect($owner->account->fresh()->hasOnboardingMcpConnection())->toBeFalse();
})->with(['unbound', 'expired', 'viewer', 'teammate']);

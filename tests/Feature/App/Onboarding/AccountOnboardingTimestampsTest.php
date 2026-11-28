<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;

test('account onboarding timestamps are not mass assignable', function () {
    $user = User::factory()->create();
    $account = $user->account;

    expect(fn () => $account->update([
        'onboarding_completed_at' => now(),
        'onboarding_dismissed_at' => now()->subMinute(),
    ]))->toThrow(MassAssignmentException::class);

    expect($account->fresh()->onboarding_completed_at)->toBeNull()
        ->and($account->fresh()->onboarding_dismissed_at)->toBeNull();
});

test('account can store onboarding completed and dismissed timestamps via forceFill', function () {
    $user = User::factory()->create();
    $account = $user->account;

    $account->forceFill([
        'onboarding_completed_at' => now(),
        'onboarding_dismissed_at' => now()->subMinute(),
    ])->save();

    $account->refresh();

    expect($account->onboarding_completed_at)->not->toBeNull()
        ->and($account->onboarding_dismissed_at)->not->toBeNull();
});

<?php

declare(strict_types=1);

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Repurpose\RepurposeTransition;

test('deleting the source account leaves the repurpose and its history intact', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->for($workspace)->create();

    $repurpose = Repurpose::factory()->for($workspace)->create([
        'source_social_account_id' => $account->id,
    ]);

    $account->delete();

    expect(Repurpose::query()->whereKey($repurpose->id)->exists())->toBeTrue()
        ->and($repurpose->fresh()->source_social_account_id)->toBeNull();
});

test('applyIfPossible returns null instead of throwing when the status moved on', function () {
    $repurpose = Repurpose::factory()->create(['status' => Status::Paused]);

    $result = RepurposeTransition::applyIfPossible(
        $repurpose,
        [Status::Active],
        fn (Repurpose $locked) => $locked->update(['status' => Status::Paused]),
    );

    expect($result)->toBeNull();
});

test('applyIfPossible applies the change and returns the fresh model', function () {
    $repurpose = Repurpose::factory()->create(['status' => Status::Active]);

    $result = RepurposeTransition::applyIfPossible(
        $repurpose,
        [Status::Active],
        fn (Repurpose $locked) => $locked->update(['status' => Status::Paused]),
    );

    expect($result?->status)->toBe(Status::Paused);
});

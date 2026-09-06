<?php

declare(strict_types=1);

use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

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

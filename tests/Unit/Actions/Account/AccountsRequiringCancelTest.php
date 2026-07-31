<?php

declare(strict_types=1);

use App\Actions\Account\AccountsRequiringCancel;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('owner delete preflight includes the shared account and member-owned personals', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;

    Workspace::factory()->create([
        'account_id' => $personalAccountId,
        'user_id' => $member->id,
    ])->members()->attach($member->id, ['role' => Role::Admin->value]);

    $member->update(['account_id' => $owner->account_id]);

    $accounts = AccountsRequiringCancel::forDeletingUser($owner, $owner->account, true);

    expect($accounts->pluck('id')->all())
        ->toContain($owner->account_id)
        ->toContain($personalAccountId);
});

test('member delete preflight only includes accounts they own', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;
    $member->update(['account_id' => $owner->account_id]);

    $accounts = AccountsRequiringCancel::forDeletingUser($member, $owner->account, false);

    expect($accounts->pluck('id')->all())->toBe([$personalAccountId]);
});

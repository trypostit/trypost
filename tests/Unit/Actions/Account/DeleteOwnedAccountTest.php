<?php

declare(strict_types=1);

use App\Actions\Account\DeleteOwnedAccount;
use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

test('deletes an empty owned account after canceling stripe', function () {
    $user = User::factory()->create();
    $accountId = $user->account_id;

    $mediaPaths = DeleteOwnedAccount::execute($user, $accountId);

    expect($mediaPaths)->toBe([])
        ->and(Account::find($accountId))->toBeNull()
        ->and($user->fresh()->account_id)->toBeNull();
});

test('purges workspaces on the owned account', function () {
    $user = User::factory()->create();
    $accountId = $user->account_id;
    $workspace = Workspace::factory()->create([
        'account_id' => $accountId,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);

    $mediaPaths = DeleteOwnedAccount::execute($user, $accountId);

    expect($mediaPaths)->toBeArray()
        ->and(Account::find($accountId))->toBeNull()
        ->and(Workspace::find($workspace->id))->toBeNull();
});

test('returns null when the account is not owned by the user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect(DeleteOwnedAccount::execute($user, $other->account_id))->toBeNull()
        ->and(Account::find($other->account_id))->not->toBeNull();
});

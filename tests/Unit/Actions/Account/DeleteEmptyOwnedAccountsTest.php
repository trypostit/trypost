<?php

declare(strict_types=1);

use App\Actions\Account\DeleteEmptyOwnedAccounts;
use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

test('deletes empty owned accounts', function () {
    $user = User::factory()->create();
    $emptyAccountId = $user->account_id;

    expect(Workspace::query()->where('account_id', $emptyAccountId)->count())->toBe(0);

    $deleted = DeleteEmptyOwnedAccounts::execute($user);

    expect($deleted)->toContain($emptyAccountId);
    expect(Account::find($emptyAccountId))->toBeNull();
    expect($user->fresh()->account_id)->toBeNull();
});

test('keeps accounts that still have workspaces', function () {
    $user = User::factory()->create();
    $accountId = $user->account_id;

    $workspace = Workspace::factory()->create([
        'account_id' => $accountId,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);

    $deleted = DeleteEmptyOwnedAccounts::execute($user);

    expect($deleted)->toBeEmpty();
    expect(Account::find($accountId))->not->toBeNull();
});

test('skips the excepted account id', function () {
    $user = User::factory()->create();
    $emptyAccountId = $user->account_id;

    $deleted = DeleteEmptyOwnedAccounts::execute(
        $user,
        exceptAccountId: $emptyAccountId,
    );

    expect($deleted)->toBeEmpty();
    expect(Account::find($emptyAccountId))->not->toBeNull();
});

test('only deletes the targeted account id', function () {
    $user = User::factory()->create();
    $firstId = $user->account_id;

    $second = Account::factory()->create(['owner_id' => $user->id]);

    $deleted = DeleteEmptyOwnedAccounts::execute(
        $user,
        onlyAccountId: $second->id,
    );

    expect($deleted)->toBe([$second->id]);
    expect(Account::find($second->id))->toBeNull();
    expect(Account::find($firstId))->not->toBeNull();
});

test('executeByIds deletes accounts after the owner user is gone', function () {
    $user = User::factory()->create();
    $emptyAccountId = $user->account_id;

    $user->delete();

    expect(Account::find($emptyAccountId))->not->toBeNull();

    $deleted = DeleteEmptyOwnedAccounts::executeByIds([$emptyAccountId]);

    expect($deleted)->toBe([$emptyAccountId]);
    expect(Account::find($emptyAccountId))->toBeNull();
});

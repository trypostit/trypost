<?php

declare(strict_types=1);

use App\Actions\Workspace\CreateWorkspace;
use App\Models\Account;
use App\Models\User;

test('CreateWorkspace persists the name', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $workspace = CreateWorkspace::execute($user, ['name' => 'Acme Inc']);

    expect($workspace->name)->toBe('Acme Inc');
    expect($workspace->account_id)->toBe($account->id);
    expect($workspace->user_id)->toBe($user->id);
});

test('CreateWorkspace switches user current workspace and attaches as admin', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id, 'current_workspace_id' => null]);

    $workspace = CreateWorkspace::execute($user, ['name' => 'Acme']);

    $user->refresh();
    expect($user->current_workspace_id)->toBe($workspace->id);
    expect($workspace->members->contains($user))->toBeTrue();

    $member = $workspace->members()->where('user_id', $user->id)->first();
    expect((bool) $member?->pivot->is_admin)->toBeTrue();
});

test('CreateWorkspace ignores unknown extra keys like logo_url', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);

    $workspace = CreateWorkspace::execute($user, [
        'name' => 'Acme',
        'logo_url' => 'https://acme.example/logo.png',
    ]);

    expect($workspace->name)->toBe('Acme');
});

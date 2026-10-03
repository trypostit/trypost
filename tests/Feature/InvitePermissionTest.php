<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('owner can invite members regardless of count', function () {
    $members = User::factory()->count(10)->create([
        'account_id' => $this->account->id,
    ]);

    foreach ($members as $member) {
        $this->workspace->members()->attach($member->id, membershipPivot('member'));
    }

    expect($this->user->can('inviteMember', $this->workspace))->toBeTrue();
});

test('a non-admin member cannot invite members', function () {
    $member = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    expect($member->can('inviteMember', $this->workspace))->toBeFalse();
});

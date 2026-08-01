<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => true]);

    $this->account = Account::factory()->create();
    $this->owner = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->owner->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);

    $this->invitee = User::factory()->create(['email' => 'invitee@example.com']);

    Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);
});

test('invitee cannot open workspace create form while invite is pending', function () {
    $this->actingAs($this->invitee)
        ->get(route('app.workspaces.create'))
        ->assertForbidden();
});

test('invitee cannot store a workspace while invite is pending', function () {
    $this->actingAs($this->invitee)
        ->post(route('app.workspaces.store'), [
            'name' => 'Shell workspace',
        ])
        ->assertForbidden();

    expect($this->invitee->fresh()->account->workspaces()->count())->toBe(0);
});

test('invitee can create a workspace after the invite is gone', function () {
    Invite::query()->delete();

    $this->actingAs($this->invitee)
        ->get(route('app.workspaces.create'))
        ->assertOk();
});

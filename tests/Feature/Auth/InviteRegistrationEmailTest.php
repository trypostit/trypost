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
    $this->invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);
});

test('register page receives the invite id', function () {
    $this->get(route('register', [
        'email' => 'invitee@example.com',
        'invite' => $this->invite->id,
        'redirect' => route('app.invites.show', $this->invite),
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('invite', $this->invite->id)
            ->where('email', 'invitee@example.com')
        );
});

test('invite registration rejects a different email than the invite', function () {
    $this->post(route('register.store'), [
        'name' => 'Invitee',
        'email' => 'other@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invite' => $this->invite->id,
        'redirect' => route('app.invites.show', $this->invite),
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'other@example.com')->exists())->toBeFalse();
});

test('invite registration allows the invited email', function () {
    $this->post(route('register.store'), [
        'name' => 'Invitee',
        'email' => 'invitee@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'invite' => $this->invite->id,
        'redirect' => route('app.invites.show', $this->invite),
    ])->assertRedirect();

    expect(User::where('email', 'invitee@example.com')->exists())->toBeTrue();
});

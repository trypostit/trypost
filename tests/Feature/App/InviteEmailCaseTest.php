<?php

declare(strict_types=1);

use App\Mail\WorkspaceInvite as WorkspaceInviteMail;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();
    config(['trypost.self_hosted' => true]);

    $this->account = Account::factory()->create();
    $this->owner = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->owner->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
});

test('a mixed-case invite email is stored lowercase', function () {
    $this->actingAs($this->owner)->post(route('app.invites.store'), [
        'email' => 'Bob@Acme.com',
        ...membershipPivot('member'),
    ])->assertSessionHasNoErrors();

    expect(Invite::query()->sole()->email)->toBe('bob@acme.com');
    Mail::assertQueued(WorkspaceInviteMail::class);
});

test('a second invite differing only by case is refused', function () {
    Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'bob@acme.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $this->actingAs($this->owner)->post(route('app.invites.store'), [
        'email' => 'BOB@acme.com',
        ...membershipPivot('member'),
    ])->assertSessionHasErrors('email');

    expect(Invite::query()->count())->toBe(1);
});

test('an invite to a registered email in another case is refused', function () {
    User::factory()->create(['email' => 'Bob@Acme.com']);

    $this->actingAs($this->owner)->post(route('app.invites.store'), [
        'email' => 'bob@acme.com',
        ...membershipPivot('member'),
    ])->assertSessionHasErrors('email');

    expect(Invite::query()->exists())->toBeFalse();
});

test('a user whose stored email differs in case accepts the invite', function () {
    $user = User::factory()->create(['email' => 'Bob@Acme.com']);
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'bob@acme.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $this->actingAs($user)
        ->post(route('app.invites.accept', $invite))
        ->assertRedirect(route('app.calendar'));

    expect($user->fresh()->account_id)->toBe($this->account->id)
        ->and($invite->fresh()->accepted_at)->not->toBeNull();
});

test('a user whose stored email differs in case declines the invite', function () {
    $user = User::factory()->create(['email' => 'Bob@Acme.com']);
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'bob@acme.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $this->actingAs($user)->post(route('app.invites.decline', $invite));

    expect(Invite::query()->exists())->toBeFalse();
});

test('registration through a legacy mixed-case invite accepts the lowercase email', function () {
    $inviteId = (string) Str::uuid();
    DB::table('invites')->insert([
        'id' => $inviteId,
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'Bob@Acme.com',
        'workspaces' => json_encode([$this->workspace->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->post(route('register.store'), [
        'name' => 'Bob',
        'email' => 'bob@acme.com',
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
        'invite' => $inviteId,
        'locale' => 'en',
    ])->assertSessionHasNoErrors();

    expect(User::query()->where('email', 'bob@acme.com')->exists())->toBeTrue();
});

test('a pending invite in another case still blocks the invitee from creating a workspace', function () {
    $invitee = User::factory()->create(['email' => 'Bob@Acme.com']);
    Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'bob@acme.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $this->actingAs($invitee)
        ->get(route('app.workspaces.create'))
        ->assertForbidden();
});

test('the migration lowercases stored invite emails', function () {
    $otherAccount = Account::factory()->create();
    insertLegacyInvites([
        ['email' => 'Carol@Acme.com', 'account_id' => $this->account->id, 'accepted_at' => null, 'created_at' => now()->subDays(3)],
        ['email' => 'Dave@Acme.com', 'account_id' => $otherAccount->id, 'accepted_at' => null, 'created_at' => now()->subDay()],
    ], $this->owner->id, $this->workspace->id);

    runLowercaseInviteEmailsMigration();

    expect(Invite::query()->orderBy('email')->pluck('email')->all())->toBe(['carol@acme.com', 'dave@acme.com']);
});

test('the migration keeps one invite per account when emails collide by case', function () {
    $ids = insertLegacyInvites([
        ['email' => 'Dave@Acme.com', 'account_id' => $this->account->id, 'accepted_at' => null, 'created_at' => now()->subDays(3)],
        ['email' => 'dave@acme.com', 'account_id' => $this->account->id, 'accepted_at' => null, 'created_at' => now()->subDay()],
        ['email' => 'Erin@Acme.com', 'account_id' => $this->account->id, 'accepted_at' => now()->subDays(2), 'created_at' => now()->subDays(4)],
        ['email' => 'ERIN@acme.com', 'account_id' => $this->account->id, 'accepted_at' => null, 'created_at' => now()->subDay()],
        ['email' => 'Fay@Acme.com', 'account_id' => $this->account->id, 'accepted_at' => null, 'created_at' => now()->subDay()],
        ['email' => 'FAY@acme.com', 'account_id' => $this->account->id, 'accepted_at' => null, 'created_at' => now()->subDays(3)],
    ], $this->owner->id, $this->workspace->id);

    runLowercaseInviteEmailsMigration();

    expect(Invite::query()->orderBy('email')->pluck('id')->all())->toBe([$ids[1], $ids[2], $ids[4]])
        ->and(Invite::query()->orderBy('email')->pluck('email')->all())->toBe(['dave@acme.com', 'erin@acme.com', 'fay@acme.com']);
})->skip(fn (): bool => DB::getDriverName() === 'mysql', 'MySQL compares emails case-insensitively, so the unique index never held a case collision.');

/**
 * @param  list<array{email: string, account_id: string, accepted_at: mixed, created_at: mixed}>  $rows
 * @return list<string>
 */
function insertLegacyInvites(array $rows, string $invitedBy, string $workspaceId): array
{
    $ids = [];

    foreach ($rows as $row) {
        $ids[] = $id = (string) Str::uuid();

        DB::table('invites')->insert([
            ...$row,
            'id' => $id,
            'invited_by' => $invitedBy,
            'workspaces' => json_encode([$workspaceId]),
            'updated_at' => $row['created_at'],
        ]);
    }

    return $ids;
}

function runLowercaseInviteEmailsMigration(): void
{
    $migration = require database_path('migrations/2026_10_06_104748_lowercase_invite_emails.php');
    $migration->up();
}

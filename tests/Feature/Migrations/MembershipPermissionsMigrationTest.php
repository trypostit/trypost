<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->migration = require collect(glob(database_path('migrations/*_add_permissions_to_user_workspace_and_invites_tables.php')))->sole();
    $this->dropRole = require collect(glob(database_path('migrations/*_drop_role_from_user_workspace_and_invites_tables.php')))->sole();
    $this->dropRole->down();
    $this->migration->down();
    $this->seeded = ['accounts' => [], 'users' => [], 'workspaces' => [], 'invites' => []];
});

afterEach(function () {
    if (! Schema::hasColumn('user_workspace', 'is_admin')) {
        $this->migration->up();
    }

    if (Schema::hasColumn('user_workspace', 'role')) {
        $this->dropRole->up();
    }

    DB::table('invites')->whereIn('id', array_values($this->seeded['invites']))->delete();
    DB::table('user_workspace')->whereIn('workspace_id', $this->seeded['workspaces'])->delete();
    DB::table('workspaces')->whereIn('id', $this->seeded['workspaces'])->delete();
    DB::table('users')->whereIn('id', $this->seeded['users'])->delete();
    DB::table('accounts')->whereIn('id', $this->seeded['accounts'])->delete();
});

/**
 * @return array{workspace: Workspace, users: array<string, User>}
 */
function membershipMigrationSeed(object $test): array
{
    $owner = User::factory()->create();
    $users = ['owner' => $owner];

    foreach (['admin', 'member', 'viewer'] as $role) {
        $users[$role] = User::factory()->create(['account_id' => $owner->account_id]);
    }

    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $test->seeded['accounts'][] = $owner->account_id;
    $test->seeded['users'] = array_map(fn (User $user): string => $user->id, array_values($users));
    $test->seeded['workspaces'][] = $workspace->id;

    $rows = ['owner' => 'viewer', 'admin' => 'admin', 'member' => 'member', 'viewer' => 'viewer'];

    foreach ($rows as $key => $role) {
        DB::table('user_workspace')->insert([
            'user_id' => $users[$key]->id,
            'workspace_id' => $workspace->id,
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach (['admin', 'member', 'viewer'] as $role) {
        $id = (string) Str::uuid();
        DB::table('invites')->insert([
            'id' => $id,
            'account_id' => $owner->account_id,
            'invited_by' => $owner->id,
            'email' => "{$role}-invite@example.com",
            'role' => $role,
            'workspaces' => json_encode([$workspace->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $test->seeded['invites'][$role] = $id;
    }

    return ['workspace' => $workspace, 'users' => $users];
}

/**
 * @return array{is_admin: bool, requires_approval: bool}
 */
function membershipMigrationFlags(string $table, string $column, string $id): array
{
    $row = (array) DB::table($table)->where($column, $id)->first(['is_admin', 'requires_approval']);

    return ['is_admin' => (bool) $row['is_admin'], 'requires_approval' => (bool) $row['requires_approval']];
}

test('maps every role to the two flags and makes account owners admins', function () {
    ['users' => $users] = membershipMigrationSeed($this);

    $this->migration->up();

    expect(membershipMigrationFlags('user_workspace', 'user_id', $users['owner']->id))->toBe(['is_admin' => true, 'requires_approval' => false])
        ->and(membershipMigrationFlags('user_workspace', 'user_id', $users['admin']->id))->toBe(['is_admin' => true, 'requires_approval' => false])
        ->and(membershipMigrationFlags('user_workspace', 'user_id', $users['member']->id))->toBe(['is_admin' => false, 'requires_approval' => false])
        ->and(membershipMigrationFlags('user_workspace', 'user_id', $users['viewer']->id))->toBe(['is_admin' => false, 'requires_approval' => true])
        ->and(membershipMigrationFlags('invites', 'id', $this->seeded['invites']['admin']))->toBe(['is_admin' => true, 'requires_approval' => false])
        ->and(membershipMigrationFlags('invites', 'id', $this->seeded['invites']['member']))->toBe(['is_admin' => false, 'requires_approval' => false])
        ->and(membershipMigrationFlags('invites', 'id', $this->seeded['invites']['viewer']))->toBe(['is_admin' => false, 'requires_approval' => true]);
});

test('rolling back restores a role for every row', function () {
    ['users' => $users] = membershipMigrationSeed($this);
    $this->migration->up();
    DB::table('user_workspace')->where('user_id', $users['member']->id)->update(['role' => null, 'requires_approval' => true]);

    $this->migration->down();

    expect(DB::table('user_workspace')->where('user_id', $users['admin']->id)->value('role'))->toBe('admin')
        ->and(DB::table('user_workspace')->where('user_id', $users['member']->id)->value('role'))->toBe('viewer')
        ->and(Schema::hasColumn('user_workspace', 'is_admin'))->toBeFalse();
});

test('the role columns are gone once both migrations ran', function () {
    membershipMigrationSeed($this);

    $this->migration->up();
    $this->dropRole->up();

    expect(Schema::hasColumn('user_workspace', 'role'))->toBeFalse()
        ->and(Schema::hasColumn('invites', 'role'))->toBeFalse();
});

test('rolling back the role drop restores every role and the invites default', function () {
    ['users' => $users, 'workspace' => $workspace] = membershipMigrationSeed($this);
    $this->migration->up();
    $this->dropRole->up();

    $this->dropRole->down();

    $id = (string) Str::uuid();
    DB::table('invites')->insert([
        'id' => $id,
        'account_id' => $workspace->account_id,
        'invited_by' => $users['owner']->id,
        'email' => 'default-invite@example.com',
        'workspaces' => json_encode([$workspace->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->seeded['invites']['default'] = $id;

    $memberRole = fn (string $key): ?string => DB::table('user_workspace')->where('user_id', $users[$key]->id)->value('role');
    $inviteRole = fn (string $key): ?string => DB::table('invites')->where('id', $this->seeded['invites'][$key])->value('role');

    expect($memberRole('owner'))->toBe('admin')
        ->and($memberRole('admin'))->toBe('admin')
        ->and($memberRole('member'))->toBe('member')
        ->and($memberRole('viewer'))->toBe('viewer')
        ->and($inviteRole('admin'))->toBe('admin')
        ->and($inviteRole('member'))->toBe('member')
        ->and($inviteRole('viewer'))->toBe('viewer')
        ->and($inviteRole('default'))->toBe('member');
});

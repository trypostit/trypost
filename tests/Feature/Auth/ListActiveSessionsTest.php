<?php

declare(strict_types=1);

use App\Actions\Auth\ListActiveSessions;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('lists the user sessions, most recent first, flagging the current one', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $table = config('session.table', 'sessions');

    DB::table($table)->insert([
        ['id' => 'older', 'user_id' => $user->id, 'ip_address' => '203.0.113.1', 'user_agent' => 'Firefox', 'payload' => '', 'last_activity' => now()->subDay()->timestamp],
        ['id' => 'current', 'user_id' => $user->id, 'ip_address' => '203.0.113.2', 'user_agent' => 'Chrome', 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'someone-else', 'user_id' => $otherUser->id, 'ip_address' => '203.0.113.3', 'user_agent' => 'Safari', 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    $sessions = ListActiveSessions::execute($user, 'current');

    expect(array_column($sessions, 'id'))->toBe(['current', 'older'])
        ->and(array_column($sessions, 'is_current'))->toBe([true, false])
        ->and($sessions[1])->toMatchArray(['ip_address' => '203.0.113.1', 'user_agent' => 'Firefox']);
});

test('lists nothing when sessions are not kept in the database', function () {
    config(['session.driver' => 'array']);

    expect(ListActiveSessions::execute(User::factory()->create(), 'any'))->toBe([]);
});

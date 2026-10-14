<?php

declare(strict_types=1);

use App\Actions\Auth\LogoutOtherSessions;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('ends the other sessions of the user and keeps the current one and other users', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $table = config('session.table', 'sessions');

    DB::table($table)->insert([
        ['id' => 'current', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'phone', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'someone-else', 'user_id' => $otherUser->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    LogoutOtherSessions::execute($user, 'current');

    expect(DB::table($table)->orderBy('id')->pluck('id')->all())->toBe(['current', 'someone-else']);
});

test('touches nothing when sessions are not kept in the database', function () {
    config(['session.driver' => 'array']);
    $user = User::factory()->create();
    $table = config('session.table', 'sessions');
    DB::table($table)->insert(['id' => 'phone', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => now()->timestamp]);

    LogoutOtherSessions::execute($user, 'current');

    expect(DB::table($table)->where('id', 'phone')->exists())->toBeTrue();
});

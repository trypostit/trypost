<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = backofficeAdmin();
    $this->user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'left-over', 'user_id' => $this->user->id, 'ip_address' => '203.0.113.7', 'user_agent' => 'Safari', 'payload' => '', 'last_activity' => now()->timestamp]);
});

test('a profile shows no sessions when another driver keeps them, whatever the table holds', function () {
    config(['session.driver' => 'array']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $this->user))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('data.last_seen.at', null)
            ->where('data.last_seen.ip', null),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'activity']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('data.sessions', []));
});

test('a profile reads the sessions table under the database driver', function () {
    config(['session.driver' => 'database']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', ['user' => $this->user, 'tab' => 'activity']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('data.sessions', 1)
            ->where('data.sessions.0.ip', '203.0.113.7'),
        );
});

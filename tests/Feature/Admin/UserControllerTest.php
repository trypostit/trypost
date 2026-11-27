<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = backofficeAdmin(['name' => 'Admin']);
});

test('lists users newest first', function () {
    $this->travel(-2)->days();
    $older = User::factory()->create(['name' => 'Older']);
    $this->travelBack();
    $newer = User::factory()->create(['name' => 'Newer']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Index')
            ->where('users.data.0.id', $newer->id)
            ->where('users.data.1.id', $this->admin->id)
            ->where('users.data.2.id', $older->id)
            ->has('users.meta')
            ->where('filters.search', ''),
        );
});

test('searches users by name or email', function () {
    $byName = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'g@example.test']);
    $byEmail = User::factory()->create(['name' => 'Someone', 'email' => 'hopper@example.test']);
    User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['search' => 'hopper']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 2)
            ->where('users.data', fn ($users) => collect($users)->pluck('id')->sort()->values()->all() === collect([$byName->id, $byEmail->id])->sort()->values()->all())
            ->where('filters.search', 'hopper'),
        );
});

test('the status filter keeps everyone or only the blocked, and a search narrows within it', function (string $status, array $names) {
    User::factory()->blocked()->create(['name' => 'Grace Hopper']);
    User::factory()->create(['name' => 'Hopper Active']);
    User::factory()->blocked()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['status' => $status, 'search' => 'HOPPER']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('users.data', fn ($users) => collect($users)->pluck('name')->sort()->values()->all() === $names)
            ->where('filters.status', $status),
        );
})->with([
    'everyone' => ['all', ['Grace Hopper', 'Hopper Active']],
    'only the blocked' => ['blocked', ['Grace Hopper']],
]);

test('a user matches a term in their name or email, whatever its case', function () {
    $byName = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'g@example.test']);
    $byEmail = User::factory()->create(['name' => 'Someone', 'email' => 'hopper@example.test']);
    User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.test']);

    expect(User::query()->matching('HoPPer')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$byName->id, $byEmail->id])->sort()->values()->all());
});

test('a user who is not an admin cannot list or open users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.show', $user))->assertForbidden();
});

test('lists and opens users on Cloud, where app access reads the subscriptions', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('users.data.0.has_app_access', false));

    $this->actingAs($this->admin)
        ->get(route('admin.users.show', $user))
        ->assertOk();
});

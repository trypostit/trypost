<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\UserSeeder;

test('seeder creates the admin user and a workspace when database is empty', function () {
    expect(User::count())->toBe(0);

    $this->seed(UserSeeder::class);

    $admin = User::where('email', 'admin@trypost.it')->first();

    expect($admin)->not->toBeNull();
    expect($admin->account_id)->not->toBeNull();
    expect($admin->workspaces()->count())->toBe(1);
});

test('seeder is idempotent when a user already exists', function () {
    User::factory()->create();

    $this->seed(UserSeeder::class);

    expect(User::where('email', 'admin@trypost.it')->exists())->toBeFalse();
});

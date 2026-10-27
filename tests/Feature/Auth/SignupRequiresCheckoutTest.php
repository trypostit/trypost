<?php

declare(strict_types=1);

use App\Actions\User\CreateUser;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    $this->seed(PlanSeeder::class);
});

test('new signup does not create a trial before checkout', function () {
    $user = CreateUser::execute([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'password' => 'password123',
        'registration_ip' => '127.0.0.1',
    ]);

    expect($user->account->plan_id)->toBeNull();
    expect($user->account->trial_ends_at)->toBeNull();
    expect($user->account->stripe_id)->toBeNull();
});

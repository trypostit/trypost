<?php

declare(strict_types=1);

use App\Actions\User\CreateUser;
use App\Enums\Plan\Slug;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    config(['trypost.billing.require_card_for_trial' => true]);
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

test('new signup creates generic trial when card is not required', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

    $user = CreateUser::execute([
        'name' => 'Alice',
        'email' => 'alice+nocard@example.com',
        'password' => 'password123',
        'registration_ip' => '127.0.0.1',
    ]);

    expect($user->account->plan->slug)->toBe(Slug::Socials);
    expect($user->account->trial_ends_at)->not->toBeNull();
});

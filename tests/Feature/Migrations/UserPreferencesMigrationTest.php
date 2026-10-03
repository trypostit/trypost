<?php

declare(strict_types=1);

use App\Enums\User\DefaultPostAction;
use App\Enums\User\Theme;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

test('users table carries the preference columns with their defaults', function () {
    expect(Schema::hasColumns('users', ['timezone', 'theme', 'time_format', 'week_starts_on', 'default_post_action']))->toBeTrue();

    $user = User::factory()->create()->fresh();

    expect($user->timezone)->toBe('UTC')
        ->and($user->theme)->toBe(Theme::DEFAULT)
        ->and($user->time_format)->toBe(TimeFormat::DEFAULT)
        ->and($user->week_starts_on)->toBe(WeekStart::DEFAULT)
        ->and($user->default_post_action)->toBe(DefaultPostAction::DEFAULT);
});

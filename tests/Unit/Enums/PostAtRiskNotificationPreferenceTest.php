<?php

declare(strict_types=1);

use App\Enums\Notification\Type;
use App\Models\NotificationPreference;
use App\Models\User;

test('PostAtRisk emails follow the account_disconnected preference', function () {
    $user = User::factory()->create();
    $user->setRelation('notificationPreference', NotificationPreference::factory()->make([
        'account_disconnected' => false,
    ]));

    expect($user->wantsEmailFor(Type::PostAtRisk))->toBeFalse();

    $user->setRelation('notificationPreference', NotificationPreference::factory()->make([
        'account_disconnected' => true,
    ]));

    expect($user->wantsEmailFor(Type::PostAtRisk))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Actions\Activity\RecordAdminAction;
use App\Enums\Activity\AdminActionType;
use App\Enums\Activity\LogName;
use App\Models\Activity;
use App\Models\User;

test('it records who did what to whom', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();

    app(RecordAdminAction::class)->execute($admin, AdminActionType::BlockUser, $user, ['reason' => 'Spam']);

    $activity = Activity::query()->inLog(LogName::Admin)->sole();

    expect($activity->event)->toBe('user.blocked')
        ->and($activity->causer->is($admin))->toBeTrue()
        ->and($activity->subject->is($user))->toBeTrue()
        ->and($activity->subject_type)->toBe('user')
        ->and($activity->getProperty('reason'))->toBe('Spam')
        ->and($activity->getProperty('ip'))->toBe('127.0.0.1');
});

test('the admin stays the causer while another user is signed in', function () {
    $admin = User::factory()->create();
    $impersonated = User::factory()->create();

    $this->actingAs($impersonated);

    app(RecordAdminAction::class)->execute($admin, AdminActionType::StopImpersonation, $impersonated);

    expect(Activity::query()->sole()->causer->is($admin))->toBeTrue();
});

test('activities are listed for a subject, newest first', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create();
    $action = app(RecordAdminAction::class);

    $action->execute($admin, AdminActionType::BlockUser, $user);
    $this->travel(1)->minute();
    $action->execute($admin, AdminActionType::UnblockUser, $user);

    expect(Activity::query()->inLog(LogName::Admin)->forSubject($user)->latest()->pluck('event')->all())
        ->toBe(['user.unblocked', 'user.blocked']);
});

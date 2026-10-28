<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleAction;

test('each action that only changes the status or queue position maps to its post changes', function (ScheduleAction $action, array $changes) {
    expect($action->postChanges())->toBe($changes);
})->with([
    'draft' => [ScheduleAction::Draft, ['status' => 'draft']],
    'publish now' => [ScheduleAction::PublishNow, ['status' => 'publishing']],
    'queue next' => [ScheduleAction::QueueNext, ['status' => 'scheduled', 'queue' => 'next']],
    'queue top' => [ScheduleAction::QueueTop, ['status' => 'scheduled', 'queue' => 'top']],
]);

test('reschedule has no post changes because it carries a time', function () {
    expect(fn () => ScheduleAction::Reschedule->postChanges())->toThrow(LogicException::class);
});

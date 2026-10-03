<?php

declare(strict_types=1);

use App\Actions\SocialAccount\GeneratePostingSchedule;
use App\Enums\SocialAccount\Platform;
use App\Support\RandomMinute;

beforeEach(function () {
    $this->app->instance(RandomMinute::class, new class extends RandomMinute
    {
        public function __invoke(): int
        {
            return 17;
        }
    });
});

test('the goal decides how many times are generated', function (int $goal) {
    $schedule = app(GeneratePostingSchedule::class)->handle(Platform::Instagram, $goal);

    expect($schedule->slotCount())->toBe($goal);
})->with([1, 3, 7, 10, 28]);

test('up to seven posts land on distinct days', function () {
    $days = app(GeneratePostingSchedule::class)->handle(Platform::LinkedIn, 7)->days();

    expect(array_map(fn (array $d): int => count($d['times']), $days))->toBe([1, 1, 1, 1, 1, 1, 1]);
});

test('times follow the best windows with the random minute', function () {
    $windows = Platform::X->recommendedPostingWindows();
    $schedule = app(GeneratePostingSchedule::class)->handle(Platform::X, 3);

    foreach (array_slice($windows, 0, 3) as [$day, $hour]) {
        expect($schedule->days()[$day]['times'])->toContain(sprintf('%02d:17', $hour));
    }
});

test('goals outside 1..28 are clamped', function () {
    $action = app(GeneratePostingSchedule::class);

    expect($action->handle(Platform::X, 0)->slotCount())->toBe(1)
        ->and($action->handle(Platform::X, 99)->slotCount())->toBe(28);
});

<?php

declare(strict_types=1);

use App\Jobs\Analytics\FinalizeAccountDailySnapshots;
use Illuminate\Console\Scheduling\Schedule;

test('follower collection and fallback are scheduled at the expected UTC times', function () {
    $events = collect(app(Schedule::class)->events());
    $collection = $events->first(fn ($event): bool => str_contains(
        (string) $event->command,
        'analytics:dispatch-account-daily',
    ));
    $finalizer = $events->first(fn ($event): bool => $event->description === FinalizeAccountDailySnapshots::class);

    expect($collection)->not->toBeNull()
        ->and($collection->expression)->toBe('0 2 * * *')
        ->and($collection->timezone)->toBe('UTC')
        ->and($collection->withoutOverlapping)->toBeTrue()
        ->and($collection->onOneServer)->toBeTrue()
        ->and($finalizer)->not->toBeNull()
        ->and($finalizer->expression)->toBe('30 23 * * *')
        ->and($finalizer->timezone)->toBe('UTC')
        ->and($finalizer->withoutOverlapping)->toBeTrue()
        ->and($finalizer->onOneServer)->toBeTrue();
});

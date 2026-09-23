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
    $discovery = $events->first(fn ($event): bool => str_contains(
        (string) $event->command,
        'analytics:dispatch-publication-discovery',
    ));
    $metrics = $events->first(fn ($event): bool => str_contains(
        (string) $event->command,
        'analytics:dispatch-publication-metrics',
    ));
    $rollout = $events->first(fn ($event): bool => str_contains(
        (string) $event->command,
        'analytics:backfill-existing',
    ));

    expect($collection)->not->toBeNull()
        ->and($collection->expression)->toBe('0 2 * * *')
        ->and($collection->timezone)->toBe('UTC')
        ->and($collection->withoutOverlapping)->toBeTrue()
        ->and($collection->onOneServer)->toBeTrue()
        ->and($finalizer)->not->toBeNull()
        ->and($finalizer->expression)->toBe('30 23 * * *')
        ->and($finalizer->timezone)->toBe('UTC')
        ->and($finalizer->withoutOverlapping)->toBeTrue()
        ->and($finalizer->onOneServer)->toBeTrue()
        ->and($discovery)->not->toBeNull()
        ->and($discovery->expression)->toBe('0 3 * * *')
        ->and($discovery->timezone)->toBe('UTC')
        ->and($discovery->withoutOverlapping)->toBeTrue()
        ->and($discovery->onOneServer)->toBeTrue();
    expect($metrics)->not->toBeNull()
        ->and($metrics->expression)->toBe('0 4 * * *')
        ->and($metrics->timezone)->toBe('UTC')
        ->and($metrics->withoutOverlapping)->toBeTrue()
        ->and($metrics->onOneServer)->toBeTrue();
    expect($rollout)->not->toBeNull()
        ->and($rollout->expression)->toBe('0 * * * *')
        ->and($rollout->withoutOverlapping)->toBeTrue()
        ->and($rollout->onOneServer)->toBeTrue();
});

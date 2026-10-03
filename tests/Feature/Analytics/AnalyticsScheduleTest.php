<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

test('analytics work is scheduled at the expected UTC times', function () {
    $events = collect(app(Schedule::class)->events());
    $command = fn (string $needle) => $events->first(fn ($event): bool => str_contains((string) $event->command, $needle));
    $collection = $command('analytics:dispatch-account-daily');
    $finalizer = $events->first(fn ($event): bool => $event->description === 'analytics:finalize-account-daily');
    $recovery = $events->first(fn ($event): bool => $event->description === 'analytics:finalize-account-daily-recovery'
        && $event->expression === '30 0 * * *');
    $discovery = $command('--except-platform=');
    $xDiscovery = $command(' --platform=');
    $metrics = $command('analytics:dispatch-publication-metrics');

    expect($collection->expression)->toBe('0 2 * * *')
        ->and($collection->timezone)->toBe('UTC')
        ->and($finalizer->expression)->toBe('30 23 * * *')
        ->and($recovery)->not->toBeNull()
        ->and($recovery->mutexName())->not->toBe($finalizer->mutexName())
        ->and((string) $discovery->command)->toContain('analytics:dispatch-publication-discovery')
        ->and($discovery->expression)->toBe('0 */3 * * *')
        ->and((string) $xDiscovery->command)->toContain('analytics:dispatch-publication-discovery')
        ->and($xDiscovery->expression)->toBe('0 */24 * * *')
        ->and($xDiscovery->mutexName())->not->toBe($discovery->mutexName())
        ->and($metrics->expression)->toBe('0 3 * * *')
        ->and($command('analytics:backfill-existing'))->toBeNull();

    foreach ([$collection, $finalizer, $recovery, $discovery, $xDiscovery, $metrics] as $event) {
        expect($event->timezone)->toBe('UTC')
            ->and($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue();
    }
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Symfony\Component\Finder\Finder;

/**
 * Laravel probes a queued job for these names with method_exists() and calls
 * whichever it finds, from its own scope. A private or protected helper that
 * happens to share a name is invoked there and fatals — Dispatcher::queue(),
 * Queue::createPayload()::backoff(), and so on. Nothing catches it in tests
 * that call handle() directly or fake the bus, and the job then fails on every
 * real dispatch.
 */
test('no queued job hides a laravel job hook behind a non-public method', function () {
    $reserved = [
        'backoff', 'batch', 'debounceId', 'debounceVia', 'deduplicationId', 'displayName',
        'failed', 'messageGroup', 'middleware', 'queue', 'retryUntil', 'shouldFailOnTimeout',
        'tries', 'uniqueFor', 'uniqueId', 'uniqueVia',
    ];

    $collisions = [];

    foreach (Finder::create()->files()->in(app_path('Jobs'))->name('*.php') as $file) {
        $class = 'App\\Jobs\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            continue;
        }

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if ($method->class === $class && ! $method->isPublic() && in_array($method->getName(), $reserved, true)) {
                $collisions[] = "{$class}::{$method->getName()}()";
            }
        }
    }

    expect($collisions)->toBe([]);
});

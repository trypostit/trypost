<?php

declare(strict_types=1);

use App\Support\Analytics\DiscoverySchedule;

test('the discovery cron runs every n hours on the hour', function (int $hours, string $expected) {
    expect(DiscoverySchedule::cron($hours))->toBe($expected);
})->with([
    [3, '0 */3 * * *'],
    [24, '0 */24 * * *'],
    [0, '0 */1 * * *'],
    [48, '0 */24 * * *'],
]);

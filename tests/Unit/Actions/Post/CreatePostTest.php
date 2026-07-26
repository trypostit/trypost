<?php

declare(strict_types=1);

use App\Actions\Post\CreatePost;
use Carbon\CarbonImmutable;

function resolvedCreatePostScheduledAt(array $data): ?CarbonImmutable
{
    $method = new ReflectionMethod(CreatePost::class, 'resolveScheduledAt');
    $value = $method->invoke(null, $data);

    return $value ? CarbonImmutable::instance($value) : null;
}

test('create post resolver does not default missing scheduled_at to today at 09 utc', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-26 15:02:37', 'UTC'));

    expect(resolvedCreatePostScheduledAt([]))->toBeNull();

    CarbonImmutable::setTestNow();
});

test('create post resolver treats explicit null scheduled_at as unscheduled', function () {
    expect(resolvedCreatePostScheduledAt(['scheduled_at' => null]))->toBeNull();
});

test('create post resolver preserves explicit future scheduled_at in utc', function () {
    $scheduledAt = resolvedCreatePostScheduledAt(['scheduled_at' => '2099-12-31T15:30:00+02:00']);

    expect($scheduledAt?->format('Y-m-d H:i:s'))->toBe('2099-12-31 13:30:00');
});

test('create post resolver keeps explicit legacy date fallback at 09 utc', function () {
    $scheduledAt = resolvedCreatePostScheduledAt(['date' => '2099-12-31']);

    expect($scheduledAt?->format('Y-m-d H:i:s'))->toBe('2099-12-31 09:00:00');
});

<?php

declare(strict_types=1);

use App\Dto\Inbox\ResumableCursor;
use Carbon\CarbonImmutable;

test('a plain time is the watermark', function () {
    $cursor = ResumableCursor::from('2026-10-16T10:00:00+00:00');

    expect($cursor->watermark?->toIso8601String())->toBe('2026-10-16T10:00:00+00:00')
        ->and($cursor->after)->toBeNull()
        ->and($cursor->stopAt(CarbonImmutable::parse('2026-09-16'))->toIso8601String())->toBe('2026-10-16T10:00:00+00:00');
});

test('no cursor stops at the backfill start', function () {
    expect(ResumableCursor::from(null)->stopAt(CarbonImmutable::parse('2026-09-16'))->toDateString())->toBe('2026-09-16');
});

test('a stored page cursor resumes there and keeps the newest time', function () {
    $cursor = ResumableCursor::from((string) json_encode(['watermark' => '2026-10-16T10:00:00+00:00', 'after' => 12345, 'newest' => '2026-10-16T11:00:00+00:00']));

    expect($cursor->after)->toBe('12345')
        ->and($cursor->newest?->toIso8601String())->toBe('2026-10-16T11:00:00+00:00');
});

test('the next cursor is the page while pages remain and the newest time once caught up', function () {
    $cursor = ResumableCursor::from('2026-10-16T10:00:00+00:00');
    $newest = CarbonImmutable::parse('2026-10-16T11:00:00+00:00');

    expect(json_decode((string) $cursor->next('page-2', $newest), true))->toEqual(['watermark' => '2026-10-16T10:00:00+00:00', 'after' => 'page-2', 'newest' => '2026-10-16T11:00:00+00:00'])
        ->and($cursor->next(null, $newest))->toBe('2026-10-16T11:00:00+00:00')
        ->and($cursor->next(null, null))->toBe('2026-10-16T10:00:00+00:00');
});

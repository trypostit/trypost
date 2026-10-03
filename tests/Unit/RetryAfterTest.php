<?php

declare(strict_types=1);

use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

test('retry after supports delay seconds and HTTP dates', function () {
    CarbonImmutable::setTestNow('2026-09-23 02:00:00 UTC');

    $seconds = new Response(new PsrResponse(429, ['Retry-After' => ' 7200 ']));
    $date = new Response(new PsrResponse(429, ['Retry-After' => 'Wed, 23 Sep 2026 06:00:00 GMT']));

    expect(RetryAfter::from($seconds)?->toIso8601String())->toBe('2026-09-23T04:00:00+00:00')
        ->and(RetryAfter::from($date)?->toIso8601String())->toBe('2026-09-23T06:00:00+00:00');
});

test('retry after ignores missing and malformed headers', function () {
    $missing = new Response(new PsrResponse(429));
    $malformed = new Response(new PsrResponse(429, ['Retry-After' => 'not a date']));

    expect(RetryAfter::from($missing))->toBeNull()
        ->and(RetryAfter::from($malformed))->toBeNull();
});

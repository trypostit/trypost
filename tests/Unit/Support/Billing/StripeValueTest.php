<?php

declare(strict_types=1);

use App\Support\Billing\StripeValue;

test('converts an amount in cents to its main unit', function () {
    expect(StripeValue::amount(1900))->toBe(19.0)
        ->and(StripeValue::amount(1999))->toBe(19.99)
        ->and(StripeValue::amount(0))->toBe(0.0);
});

test('returns no amount without an integer', function (mixed $cents) {
    expect(StripeValue::amount($cents))->toBeNull();
})->with([
    'null' => [null],
    'string' => ['1900'],
    'float' => [19.0],
]);

test('upper-cases a currency and returns none without one', function () {
    expect(StripeValue::currency('brl'))->toBe('BRL')
        ->and(StripeValue::currency(null))->toBeNull()
        ->and(StripeValue::currency('  '))->toBeNull()
        ->and(StripeValue::currency(['usd']))->toBeNull();
});

test('reads a unix timestamp as a UTC instant and returns none without one', function () {
    expect(StripeValue::timestamp(1_790_003_600)?->toIso8601String())->toBe('2026-09-21T15:13:20+00:00')
        ->and(StripeValue::timestamp(null))->toBeNull()
        ->and(StripeValue::timestamp('soon'))->toBeNull();
});

<?php

declare(strict_types=1);

use App\Support\Billing\StripeAmount;

test('converts an amount in cents to its main unit', function () {
    expect(StripeAmount::toDecimal(1900, 'usd'))->toBe(19.0)
        ->and(StripeAmount::toDecimal(1999, 'EUR'))->toBe(19.99);
});

test('keeps a zero-decimal currency amount as is', function () {
    expect(StripeAmount::toDecimal(2500, 'jpy'))->toBe(2500.0)
        ->and(StripeAmount::toDecimal(2500, 'KRW'))->toBe(2500.0);
});

test('returns null without an integer amount', function (mixed $amount) {
    expect(StripeAmount::toDecimal($amount, 'usd'))->toBeNull();
})->with([
    'null' => [null],
    'string' => ['1900'],
    'float' => [19.0],
]);

test('returns null without a currency', function (mixed $currency) {
    expect(StripeAmount::toDecimal(1900, $currency))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['  '],
]);

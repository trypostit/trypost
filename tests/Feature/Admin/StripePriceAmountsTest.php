<?php

declare(strict_types=1);

use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\StripePriceAmounts;
use Illuminate\Support\Once;
use Stripe\ApiRequestor;

afterEach(fn () => ApiRequestor::setHttpClient(null));

test('a price is worth its amount per month, whatever interval Stripe bills it on', function (array $price, float $monthly) {
    fakeStripeReads(['price_any' => $price]);

    expect(round(StripePriceAmounts::monthlyCents('price_any'), 2))->toBe($monthly);
})->with([
    'monthly' => [[1900, 'month'], 1900.0],
    'every three months' => [[5700, 'month', 3], 1900.0],
    'yearly' => [[19000, 'year'], round(19000 / 12, 2)],
    'weekly' => [[500, 'week'], round(500 * 52 / 12, 2)],
]);

test('a subscription is worth its price times its quantity', function () {
    fakeStripeReads(['price_any' => [1900, 'month']]);
    $subscription = subscribeAccount(User::factory()->create()->account, ['stripe_price' => 'price_any', 'quantity' => 3]);

    expect(StripePriceAmounts::forSubscription($subscription))->toBe(5700.0)
        ->and(StripePriceAmounts::forSubscription(new Subscription(['stripe_price' => null])))->toBeNull();
});

test('a price is read from Stripe once and kept', function () {
    $stripe = fakeStripeReads(['price_any' => [1900, 'month']]);

    StripePriceAmounts::monthlyCents('price_any');
    StripePriceAmounts::monthlyCents('price_any');
    Once::flush();
    StripePriceAmounts::monthlyCents('price_any');

    expect($stripe->requests)->toBe(1);
});

test('a price Stripe cannot tell is unknown, and kept a minute before Stripe is asked again', function () {
    $stripe = fakeStripeReads(down: true);

    expect(StripePriceAmounts::monthlyCents('price_any'))->toBeNull();

    Once::flush();
    StripePriceAmounts::monthlyCents('price_any');

    expect($stripe->requests)->toBe(1);

    $this->travel(StripePriceAmounts::UNKNOWN_SECONDS + 1)->seconds();
    Once::flush();
    StripePriceAmounts::monthlyCents('price_any');

    expect($stripe->requests)->toBe(2);
});

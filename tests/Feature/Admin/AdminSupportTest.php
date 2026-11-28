<?php

declare(strict_types=1);

use App\Support\Billing\StripeDashboard;

test('a Stripe customer links to the live dashboard on live keys and to test mode on test keys', function () {
    config(['cashier.secret' => 'sk_live_fake']);

    expect(StripeDashboard::customerUrl('cus_1'))->toBe('https://dashboard.stripe.com/customers/cus_1')
        ->and(StripeDashboard::customerUrl(null))->toBeNull();

    config(['cashier.secret' => 'sk_test_fake']);

    expect(StripeDashboard::customerUrl('cus_1'))->toBe('https://dashboard.stripe.com/test/customers/cus_1');
});

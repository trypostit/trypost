<?php

declare(strict_types=1);

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Support\Admin\StripeDashboard;

test('the 60 and 90 day presets end today and cover that many days', function (string $preset, int $days) {
    $today = now('America/Sao_Paulo')->startOfDay();

    expect((new ResolveAnalyticsRangePreset)->handle($preset, null, null, 'America/Sao_Paulo'))
        ->toBe(['start' => $today->subDays($days - 1)->toDateString(), 'end' => $today->toDateString()]);
})->with([
    '60d' => ['60d', 60],
    '90d' => ['90d', 90],
]);

test('a Stripe customer links to the live dashboard on live keys and to test mode on test keys', function () {
    config(['cashier.secret' => 'sk_live_fake']);

    expect(StripeDashboard::customerUrl('cus_1'))->toBe('https://dashboard.stripe.com/customers/cus_1')
        ->and(StripeDashboard::customerUrl(null))->toBeNull();

    config(['cashier.secret' => 'sk_test_fake']);

    expect(StripeDashboard::customerUrl('cus_1'))->toBe('https://dashboard.stripe.com/test/customers/cus_1');
});

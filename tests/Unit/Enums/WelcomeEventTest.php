<?php

declare(strict_types=1);

use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\WelcomeEvent;

test('welcome dashboard funnel puts connect between referral and checkout.started', function () {
    expect(WelcomeEvent::dashboardFunnel())->toBe([
        WelcomeEvent::Persona->value,
        WelcomeEvent::Goals->value,
        WelcomeEvent::Referral->value,
        WelcomeEvent::Connect->value,
        CheckoutEvent::Started->value,
    ]);
});

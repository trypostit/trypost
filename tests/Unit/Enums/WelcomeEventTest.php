<?php

declare(strict_types=1);

use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\WelcomeEvent;

test('welcome funnel goes from persona through software to checkout.started', function () {
    expect(WelcomeEvent::funnel())->toBe([
        WelcomeEvent::Persona->value,
        WelcomeEvent::Goals->value,
        WelcomeEvent::Software->value,
        CheckoutEvent::Started->value,
    ]);
});

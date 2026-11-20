<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum WelcomeEvent: string
{
    case Persona = 'welcome.persona';
    case Goals = 'welcome.goals';
    case Software = 'welcome.software';
    case Referral = 'welcome.referral';

    /**
     * Welcome capture order through Stripe Checkout.
     *
     * @return list<string>
     */
    public static function funnel(): array
    {
        return [
            self::Persona->value,
            self::Goals->value,
            self::Software->value,
            CheckoutEvent::Started->value,
        ];
    }
}

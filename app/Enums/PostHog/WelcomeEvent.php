<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum WelcomeEvent: string
{
    case Persona = 'welcome.persona';
    case Goals = 'welcome.goals';
    case Referral = 'welcome.referral';
    case Connect = 'welcome.connect';

    /**
     * Capture events in dashboard funnel order. Connect sits between
     * Referral and checkout.started — do not jump those two steps.
     *
     * @return list<string>
     */
    public static function dashboardFunnel(): array
    {
        return [
            self::Persona->value,
            self::Goals->value,
            self::Referral->value,
            self::Connect->value,
            CheckoutEvent::Started->value,
        ];
    }
}

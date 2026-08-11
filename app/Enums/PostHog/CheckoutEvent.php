<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum CheckoutEvent: string
{
    case Started = 'checkout.started';
    case Completed = 'checkout.completed';
}

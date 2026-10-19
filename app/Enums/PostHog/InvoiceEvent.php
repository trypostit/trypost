<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum InvoiceEvent: string
{
    case PaymentFailed = 'invoice.payment_failed';
    case Upcoming = 'invoice.upcoming';
}

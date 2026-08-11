<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\CheckoutEvent;
use App\Models\Account;
use App\Support\StripeSubscriptionConversion;

class TrackCheckoutCompleted extends AbstractTrackStripeSubscriptionEvent
{
    protected function event(): string
    {
        return CheckoutEvent::Completed->value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function properties(Account $account): array
    {
        return StripeSubscriptionConversion::propertiesFor($account, $this->payload);
    }
}

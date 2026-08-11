<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\TrialEvent;
use App\Models\Account;
use App\Support\StripeSubscriptionConversion;

class TrackTrialConverted extends AbstractTrackStripeSubscriptionEvent
{
    protected function event(): string
    {
        return TrialEvent::Converted->value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function properties(Account $account): array
    {
        return StripeSubscriptionConversion::propertiesFor($account, $this->payload);
    }
}

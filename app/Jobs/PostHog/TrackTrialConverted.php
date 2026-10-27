<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\TrialEvent;
use App\Models\Account;
use App\Support\StripeSubscriptionConversion;

class TrackTrialConverted extends AbstractTrackStripeEvent
{
    /**
     * False when checkout.completed reports the same payment (a trial without
     * a card subscribing through Checkout), so revenue is counted once. A
     * default, not a promoted property, so jobs queued before it still run.
     */
    public bool $withRevenue = true;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(string $accountId, array $payload, bool $withRevenue = true)
    {
        parent::__construct($accountId, $payload);

        $this->withRevenue = $withRevenue;
    }

    protected function shouldCapture(Account $account): bool
    {
        return filled($account->plan);
    }

    protected function event(): string
    {
        return TrialEvent::Converted->value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function properties(Account $account): array
    {
        return $this->withRevenue
            ? StripeSubscriptionConversion::propertiesFor($account, $this->payload)
            : StripeSubscriptionConversion::baseProperties($account, $this->payload);
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\CheckoutEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Support\Billing\ConfigureSubscriptionCheckout;

class TrackCheckoutExpired extends AbstractTrackStripeEvent
{
    protected function shouldCapture(Account $account): bool
    {
        return ! $account->subscribed(Account::SUBSCRIPTION_NAME);
    }

    protected function event(): string
    {
        return CheckoutEvent::Expired->value;
    }

    /**
     * @return array{plan_name: string|null, interval: string|null, offer: string|null, checkout_session_id: string|null}
     */
    protected function properties(Account $account): array
    {
        $metadata = data_get($this->payload, 'data.object.metadata', []);
        $planSlug = data_get($metadata, ConfigureSubscriptionCheckout::PLAN_METADATA_KEY);

        return [
            'plan_name' => filled($planSlug) ? Plan::where('slug', $planSlug)->value('name') : null,
            'interval' => data_get($metadata, ConfigureSubscriptionCheckout::INTERVAL_METADATA_KEY),
            'offer' => data_get($metadata, ConfigureSubscriptionCheckout::OFFER_SLUG_METADATA_KEY),
            'checkout_session_id' => data_get($this->payload, 'data.object.id'),
        ];
    }
}

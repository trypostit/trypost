<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\CheckoutEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Services\PostHogService;
use App\Support\Billing\ConfigureSubscriptionCheckout;

class TrackCheckoutExpired extends AbstractTrackStripeSubscriptionEvent
{
    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::shouldTrack()) {
            return;
        }

        $account = Account::find($this->accountId);

        if (! $account?->owner_id || $account->subscribed(Account::SUBSCRIPTION_NAME)) {
            return;
        }

        $postHog->capture(
            (string) $account->owner_id,
            $this->event(),
            $this->properties($account),
            $account,
        );
    }

    protected function event(): string
    {
        return CheckoutEvent::Expired->value;
    }

    /**
     * @return array{plan: string|null, interval: string|null, is_first_month_offer: bool, checkout_session_id: string|null}
     */
    protected function properties(Account $account): array
    {
        $metadata = data_get($this->payload, 'data.object.metadata', []);
        $planSlug = data_get($metadata, ConfigureSubscriptionCheckout::PLAN_METADATA_KEY);

        return [
            'plan' => filled($planSlug) ? Plan::where('slug', $planSlug)->value('name') : null,
            'interval' => data_get($metadata, ConfigureSubscriptionCheckout::INTERVAL_METADATA_KEY),
            'is_first_month_offer' => data_get($metadata, ConfigureSubscriptionCheckout::FIRST_MONTH_OFFER_METADATA_KEY) === '1',
            'checkout_session_id' => data_get($this->payload, 'data.object.id'),
        ];
    }
}

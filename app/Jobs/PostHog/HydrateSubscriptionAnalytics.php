<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Models\Subscription;
use App\Support\Billing\SubscriptionAnalytics;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HydrateSubscriptionAnalytics implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public string $subscriptionId)
    {
        $this->onQueue('posthog');
    }

    public function uniqueId(): string
    {
        return $this->subscriptionId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(SubscriptionAnalytics $analytics): void
    {
        $subscription = Subscription::query()->with('owner')->find($this->subscriptionId);

        if (! $subscription?->owner) {
            return;
        }

        $analytics->syncFromStripe($subscription, $subscription->asStripeSubscription(['discounts']));

        SyncAccountUsage::dispatch((string) $subscription->account_id);
    }
}

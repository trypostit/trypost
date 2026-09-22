<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Support\Billing\SubscriptionAnalytics;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;
use Throwable;

class SyncSubscriptionAnalytics
{
    public function __construct(private SubscriptionAnalytics $analytics) {}

    public function handle(WebhookHandled $event): void
    {
        $type = data_get($event->payload, 'type');

        if (! in_array($type, [
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
        ], true)) {
            return;
        }

        try {
            $stripeSubscription = data_get($event->payload, 'data.object', []);
            $account = Account::query()
                ->where('stripe_id', data_get($stripeSubscription, 'customer'))
                ->first();

            if (! $account) {
                return;
            }

            $subscription = $account->subscriptions()
                ->where('stripe_id', data_get($stripeSubscription, 'id'))
                ->first();

            if ($subscription && $type !== 'customer.subscription.deleted') {
                $this->analytics->syncFromPayload($subscription, $stripeSubscription);
            }

            SyncAccountUsage::dispatch((string) $account->id);
        } catch (Throwable $exception) {
            Log::warning('Failed to sync subscription analytics', [
                'stripe_event_id' => data_get($event->payload, 'id'),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

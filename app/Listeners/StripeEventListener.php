<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\PostHog\BillingEvent;
use App\Jobs\PostHog\TrackBilling;
use App\Jobs\PostHog\TrackCheckoutCompleted;
use App\Jobs\PostHog\TrackTrialConverted;
use App\Jobs\PostHog\TrackTrialStarted;
use App\Models\Account;
use App\Models\Plan;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

class StripeEventListener
{
    public function handle(WebhookReceived $event): void
    {
        try {
            $type = data_get($event->payload, 'type');
            $stripeCustomerId = data_get($event->payload, 'data.object.customer');

            if (! $stripeCustomerId) {
                return;
            }

            $account = Account::where('stripe_id', $stripeCustomerId)->first();

            if (! $account) {
                return;
            }

            match ($type) {
                'customer.subscription.created' => $this->handleSubscriptionCreated($account, $event->payload),
                'customer.subscription.updated' => $this->handleSubscriptionUpdated($account, $event->payload),
                'customer.subscription.deleted' => $this->handleSubscriptionDeleted($account, $event->payload),
                default => null,
            };
        } catch (\Exception $e) {
            Log::error('Stripe webhook error: '.$e->getMessage(), [
                'exception' => $e,
                'payload' => $event->payload,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCreated(Account $account, array $payload): void
    {
        $previousPlan = $account->plan?->name;

        if ($plan = $this->resolvePlanFromSubscriptionItems($payload, $account)) {
            $account->update([
                'plan_id' => $plan->id,
                'trial_ends_at' => null,
            ]);
        }

        $this->trackPlanChange($account, BillingEvent::Created, $previousPlan, $payload);
        $this->trackSubscriptionStart($account, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionUpdated(Account $account, array $payload): void
    {
        $previousPlan = $account->plan?->name;

        if ($plan = $this->resolvePlanFromSubscriptionItems($payload, $account)) {
            $account->update(['plan_id' => $plan->id]);
        }

        $this->trackPlanChange($account, BillingEvent::Updated, $previousPlan, $payload);
        $this->trackTrialConversion($account, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionDeleted(Account $account, array $payload): void
    {
        if ($account->plan_id === null) {
            return;
        }

        $previousPlan = $account->plan?->name;

        $account->update(['plan_id' => null]);

        $this->trackPlanChange($account, BillingEvent::Cancelled, $previousPlan, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolvePlanFromSubscriptionItems(array $payload, Account $account): ?Plan
    {
        $priceIds = collect(data_get($payload, 'data.object.items.data', []))
            ->pluck('price.id')
            ->filter()
            ->all();

        if (empty($priceIds)) {
            return null;
        }

        $plan = Plan::query()
            ->where(function ($query) use ($priceIds): void {
                $query->whereIn('stripe_monthly_price_id', $priceIds)
                    ->orWhereIn('stripe_yearly_price_id', $priceIds);
            })
            ->first();

        if (! $plan) {
            Log::warning('Stripe webhook: no matching plan found in subscription items', [
                'account_id' => $account->id,
                'price_ids' => $priceIds,
            ]);
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function trackPlanChange(Account $account, BillingEvent $event, ?string $previousPlan, array $payload): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        TrackBilling::dispatch((string) $account->id, $event, $payload, $previousPlan);
    }

    /**
     * A subscription is born either `trialing` (card-required trial, no
     * charge yet) or `active` (first-month coupon or no trial — charged
     * immediately). These are different business events, not the same
     * "checkout completed" moment — see App\Support\StripeSubscriptionConversion.
     *
     * @param  array<string, mixed>  $payload
     */
    private function trackSubscriptionStart(Account $account, array $payload): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        match (data_get($payload, 'data.object.status')) {
            'trialing' => TrackTrialStarted::dispatch((string) $account->id, $payload),
            'active' => TrackCheckoutCompleted::dispatch((string) $account->id, $payload),
            default => null,
        };
    }

    /**
     * Fires only on the specific `trialing` -> `active` transition (the
     * trial's first successful charge), using Stripe's own
     * `previous_attributes` rather than trusting local DB state — Cashier's
     * WebhookController dispatches WebhookReceived before it updates the
     * local subscription row, so relying on our own `stripe_status` here
     * would be fragile if that internal ordering ever changes.
     *
     * @param  array<string, mixed>  $payload
     */
    private function trackTrialConversion(Account $account, array $payload): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $wasTrialing = data_get($payload, 'data.previous_attributes.status') === 'trialing';
        $isNowActive = data_get($payload, 'data.object.status') === 'active';

        if ($wasTrialing && $isNowActive) {
            TrackTrialConverted::dispatch((string) $account->id, $payload);
        }
    }
}

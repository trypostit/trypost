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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

class StripeEventListener
{
    public function handle(WebhookReceived $event): void
    {
        try {
            if ($this->alreadyProcessed(data_get($event->payload, 'id'))) {
                return;
            }

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
     * Stripe redelivers the same event id on a timeout/non-2xx response (and
     * it can otherwise reach us more than once, e.g. a duplicated local
     * forwarding setup). Cache::add is atomic, so only the first delivery of
     * a given event id returns false here — every side effect further down
     * (plan_id updates, PostHog dispatches) only ever runs once per event.
     */
    private function alreadyProcessed(?string $eventId): bool
    {
        if (! $eventId) {
            return false;
        }

        return ! Cache::add("stripe_webhook_event:{$eventId}", true, now()->addDay());
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
        if (! PostHogService::shouldTrack()) {
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
        if (! PostHogService::shouldTrack()) {
            return;
        }

        match ($this->currentStatus($payload)) {
            'trialing' => TrackTrialStarted::dispatch((string) $account->id, $payload),
            'active' => TrackCheckoutCompleted::dispatch((string) $account->id, $payload),
            default => null,
        };
    }

    /**
     * Fires on the trial's first successful charge, using Stripe's own
     * `previous_attributes` rather than trusting local DB state — Cashier's
     * WebhookController dispatches WebhookReceived before it updates the
     * local subscription row, so relying on our own `stripe_status` here
     * would be fragile if that internal ordering ever changes.
     *
     * @param  array<string, mixed>  $payload
     */
    private function trackTrialConversion(Account $account, array $payload): void
    {
        if (! PostHogService::shouldTrack()) {
            return;
        }

        if ($this->convertedFromTrial($payload) && $this->isNowActive($payload)) {
            TrackTrialConverted::dispatch((string) $account->id, $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function currentStatus(array $payload): mixed
    {
        return data_get($payload, 'data.object.status');
    }

    /**
     * True for a transition originating from a trial: either the immediate
     * `trialing` -> `active` charge, or a `past_due` -> `active` recovery
     * after the trial's first charge attempt failed. `trial_end` is never
     * cleared by Stripe once set, so it can't by itself distinguish that
     * recovery from an unrelated `past_due` -> `active` recovery on a much
     * later billing cycle (e.g. a long-time paying customer's card getting
     * declined and then updated). The item's `current_period_start` is what
     * actually pins this to the trial's own first billing period — it only
     * equals `trial_end` for that first period, never for a later one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function convertedFromTrial(array $payload): bool
    {
        $previousStatus = data_get($payload, 'data.previous_attributes.status');

        if ($previousStatus === 'trialing') {
            return true;
        }

        if ($previousStatus !== 'past_due') {
            return false;
        }

        $trialEnd = data_get($payload, 'data.object.trial_end');
        $currentPeriodStart = data_get($payload, 'data.object.items.data.0.current_period_start');

        return $trialEnd !== null && $currentPeriodStart === $trialEnd;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isNowActive(array $payload): bool
    {
        return $this->currentStatus($payload) === 'active';
    }
}

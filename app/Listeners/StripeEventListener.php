<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Offer\RedeemOffer;
use App\Enums\PostHog\BillingEvent;
use App\Jobs\PostHog\TrackBilling;
use App\Jobs\PostHog\TrackCheckoutCompleted;
use App\Jobs\PostHog\TrackPlanChanged;
use App\Jobs\PostHog\TrackTrialConverted;
use App\Jobs\PostHog\TrackTrialStarted;
use App\Models\Account;
use App\Models\Plan;
use App\Services\PostHogService;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use App\Support\Billing\SubscriptionPlanSync;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Stripe\Event as StripeEvent;
use Stripe\Subscription as StripeSubscription;
use Throwable;

class StripeEventListener
{
    public function handle(WebhookHandled $event): void
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

            /** @var Account|null $account */
            $account = Cashier::findBillable($stripeCustomerId);

            if (! $account) {
                return;
            }

            match ($type) {
                StripeEvent::TYPE_CUSTOMER_SUBSCRIPTION_CREATED => $this->handleSubscriptionCreated($account, $event->payload),
                StripeEvent::TYPE_CUSTOMER_SUBSCRIPTION_UPDATED => $this->handleSubscriptionUpdated($account, $event->payload),
                StripeEvent::TYPE_CUSTOMER_SUBSCRIPTION_DELETED => $this->handleSubscriptionDeleted($account, $event->payload),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error("Stripe webhook error: {$e->getMessage()}", [
                'exception' => $e,
                'payload' => $event->payload,
            ]);
        }
    }

    /**
     * A subscription is born either `trialing` (card-required trial, no charge
     * yet) or `active` (an offer without a trial, or no offer, charged at once): two
     * business events, see App\Support\StripeSubscriptionConversion.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionCreated(Account $account, array $payload): void
    {
        $previousPlan = $account->plan?->name;

        $this->redeemOffer($account, $payload);
        $this->syncPlanFromSubscription($account, $payload, endsGenericTrial: true);
        $this->recordFirstPayment($account, $payload);

        TrackBilling::dispatchIf(PostHogService::shouldTrack(), (string) $account->id, BillingEvent::Created, $payload, $previousPlan);
        TrackTrialStarted::dispatchIf(PostHogService::shouldTrack() && $this->currentStatus($payload) === StripeSubscription::STATUS_TRIALING, (string) $account->id, $payload);
        TrackCheckoutCompleted::dispatchIf(PostHogService::shouldTrack() && $this->currentStatus($payload) === StripeSubscription::STATUS_ACTIVE, (string) $account->id, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionUpdated(Account $account, array $payload): void
    {
        $previousPlan = $account->plan?->name;

        $this->redeemOffer($account, $payload);
        $this->syncPlanFromSubscription($account, $payload);
        $this->recordFirstPayment($account, $payload);

        TrackBilling::dispatchIf(PostHogService::shouldTrack(), (string) $account->id, BillingEvent::Updated, $payload, $previousPlan);
        TrackTrialConverted::dispatchIf(PostHogService::shouldTrack() && $this->convertedFromTrial($payload) && $this->isNowActive($payload), (string) $account->id, $payload);
        TrackPlanChanged::dispatchIf(PostHogService::shouldTrack() && $this->priceChanged($payload), (string) $account->id, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleSubscriptionDeleted(Account $account, array $payload): void
    {
        if ($account->plan_id === null && ! $account->hasPaidSubscription()) {
            return;
        }

        $previousPlan = $account->plan?->name;

        if ($account->plan_id !== null) {
            $account->update(['plan_id' => null]);
        }

        TrackBilling::dispatchIf(
            PostHogService::shouldTrack() && $this->claimCancellation($payload),
            (string) $account->id,
            BillingEvent::Cancelled,
            $payload,
            $previousPlan,
        );
    }

    /**
     * One subscription.cancelled per subscription. A dunning that ends in
     * `unpaid` clears plan_id before the delete arrives, so plan_id can no
     * longer tell a first delivery from one Stripe redelivers days later.
     *
     * @param  array<string, mixed>  $payload
     */
    private function claimCancellation(array $payload): bool
    {
        $subscriptionId = data_get($payload, 'data.object.id');

        return blank($subscriptionId) || Cache::add("posthog:subscription_cancelled:{$subscriptionId}", true, now()->addDays(30));
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
     * The first time a subscription is active the account has been charged:
     * kept on the account because Cashier clears `trial_ends_at` on delete.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordFirstPayment(Account $account, array $payload): void
    {
        if (! $this->isNowActive($payload) || filled($account->first_paid_at)) {
            return;
        }

        Account::withoutTimestamps(fn (): bool => $account->forceFill(['first_paid_at' => now()])->saveQuietly());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncPlanFromSubscription(Account $account, array $payload, bool $endsGenericTrial = false): void
    {
        $status = $this->currentStatus($payload);

        if (SubscriptionPlanSync::allows($status)) {
            $plan = $this->resolvePlanFromSubscriptionItems($payload, $account);

            if ($plan === null) {
                return;
            }

            $attributes = ['plan_id' => $plan->id];

            if ($endsGenericTrial) {
                $attributes['trial_ends_at'] = null;
            }

            $account->update($attributes);

            return;
        }

        if (SubscriptionPlanSync::clears($status) && $account->plan_id !== null) {
            $account->update(['plan_id' => null]);
        }
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

        $plan = Plan::forStripePrices($priceIds)->first();

        if (! $plan) {
            Log::warning('Stripe webhook: no matching plan found in subscription items', [
                'account_id' => $account->id,
                'price_ids' => $priceIds,
            ]);
        }

        return $plan;
    }

    /**
     * An offer is used once its subscription is active or trialing: an
     * incomplete one may never be paid, and would use up a place for nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    private function redeemOffer(Account $account, array $payload): void
    {
        if (! SubscriptionPlanSync::allows($this->currentStatus($payload))) {
            return;
        }

        RedeemOffer::execute($account, data_get($payload, ['data', 'object', 'metadata', ConfigureSubscriptionCheckout::OFFER_METADATA_KEY]));
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

        if ($previousStatus === StripeSubscription::STATUS_TRIALING) {
            return true;
        }

        if ($previousStatus !== StripeSubscription::STATUS_PAST_DUE) {
            return false;
        }

        $trialEnd = data_get($payload, 'data.object.trial_end');
        $currentPeriodStart = data_get($payload, 'data.object.items.data.0.current_period_start');

        return $trialEnd !== null && $currentPeriodStart === $trialEnd;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function priceChanged(array $payload): bool
    {
        $previousPriceId = data_get($payload, 'data.previous_attributes.items.data.0.price.id');

        return filled($previousPriceId) && $previousPriceId !== data_get($payload, 'data.object.items.data.0.price.id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isNowActive(array $payload): bool
    {
        return $this->currentStatus($payload) === StripeSubscription::STATUS_ACTIVE;
    }
}

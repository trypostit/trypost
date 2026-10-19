<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\PostHog\AbstractTrackStripeEvent;
use App\Jobs\PostHog\TrackCheckoutExpired;
use App\Jobs\PostHog\TrackInvoicePaymentFailed;
use App\Jobs\PostHog\TrackInvoiceUpcoming;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

class StripeBillingSignalListener
{
    /**
     * @var array<string, class-string<AbstractTrackStripeEvent>>
     */
    public const JOBS = [
        'checkout.session.expired' => TrackCheckoutExpired::class,
        'invoice.payment_failed' => TrackInvoicePaymentFailed::class,
        'invoice.upcoming' => TrackInvoiceUpcoming::class,
    ];

    public function handle(WebhookReceived $event): void
    {
        try {
            $type = data_get($event->payload, 'type');

            $job = self::JOBS[$type] ?? null;

            if (blank($job)) {
                return;
            }

            if ($this->alreadyProcessed(data_get($event->payload, 'id'))) {
                return;
            }

            $stripeCustomerId = data_get($event->payload, 'data.object.customer');

            if (blank($stripeCustomerId) || ! PostHogService::shouldTrack()) {
                return;
            }

            if ($type === 'checkout.session.expired' && data_get($event->payload, 'data.object.mode') !== 'subscription') {
                return;
            }

            /** @var Account|null $account */
            $account = Cashier::findBillable($stripeCustomerId);

            if (! $account) {
                return;
            }

            $job::dispatch((string) $account->id, $event->payload);
        } catch (Throwable $e) {
            Log::error("Stripe billing signal error: {$e->getMessage()}", [
                'exception' => $e,
                'payload' => $event->payload,
            ]);
        }
    }

    private function alreadyProcessed(?string $eventId): bool
    {
        if (blank($eventId)) {
            return false;
        }

        return ! Cache::add("stripe_billing_signal:{$eventId}", true, now()->addDay());
    }
}

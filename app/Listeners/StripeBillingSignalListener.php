<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\PostHog\TrackCheckoutExpired;
use App\Jobs\PostHog\TrackInvoicePaymentFailed;
use App\Jobs\PostHog\TrackInvoiceUpcoming;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;
use Throwable;

class StripeBillingSignalListener
{
    public const HANDLED_TYPES = [
        'checkout.session.expired',
        'invoice.payment_failed',
        'invoice.upcoming',
    ];

    public function handle(WebhookReceived $event): void
    {
        try {
            $type = data_get($event->payload, 'type');

            if (! in_array($type, self::HANDLED_TYPES, true)) {
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

            $account = Account::where('stripe_id', $stripeCustomerId)->first();

            if (! $account) {
                return;
            }

            $job = match ($type) {
                'checkout.session.expired' => TrackCheckoutExpired::class,
                'invoice.payment_failed' => TrackInvoicePaymentFailed::class,
                'invoice.upcoming' => TrackInvoiceUpcoming::class,
            };

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

<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\PostHog\TrackCheckoutExpired;
use App\Jobs\PostHog\TrackInvoicePaymentFailed;
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

            if (blank($stripeCustomerId)) {
                return;
            }

            $account = Account::where('stripe_id', $stripeCustomerId)->first();

            if (! $account) {
                return;
            }

            match ($type) {
                'checkout.session.expired' => $this->handleCheckoutExpired($account, $event->payload),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($account, $event->payload),
            };
        } catch (Throwable $e) {
            Log::error("Stripe billing signal error: {$e->getMessage()}", [
                'exception' => $e,
                'payload' => $event->payload,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleCheckoutExpired(Account $account, array $payload): void
    {
        if (data_get($payload, 'data.object.mode') !== 'subscription') {
            return;
        }

        if (! PostHogService::shouldTrack()) {
            return;
        }

        TrackCheckoutExpired::dispatch((string) $account->id, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleInvoicePaymentFailed(Account $account, array $payload): void
    {
        if (! PostHogService::shouldTrack()) {
            return;
        }

        TrackInvoicePaymentFailed::dispatch((string) $account->id, $payload);
    }

    private function alreadyProcessed(?string $eventId): bool
    {
        if (blank($eventId)) {
            return false;
        }

        return ! Cache::add("stripe_billing_signal:{$eventId}", true, now()->addDay());
    }
}

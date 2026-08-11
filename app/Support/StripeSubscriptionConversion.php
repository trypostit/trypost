<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;

final class StripeSubscriptionConversion
{
    /**
     * Shared plan/interval/persona/conversion_* properties for a PostHog
     * capture backed by a Stripe subscription webhook payload. Used by both
     * TrackCheckoutCompleted (immediate charge at signup) and
     * TrackTrialConverted (trial's first successful charge) — same shape,
     * different moment in the billing lifecycle.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function propertiesFor(Account $account, array $payload): array
    {
        $unitAmount = data_get($payload, 'data.object.items.data.0.price.unit_amount');
        $currency = data_get($payload, 'data.object.items.data.0.price.currency');
        $priceId = data_get($payload, 'data.object.items.data.0.price.id');

        $properties = [
            'plan_name' => $account->plan->name,
            'interval' => $priceId === $account->plan->stripe_yearly_price_id ? 'yearly' : 'monthly',
            'persona' => $account->owner?->persona?->value,
        ];

        if (is_int($unitAmount) && is_string($currency)) {
            $properties['conversion_value'] = (float) ($unitAmount / 100);
            $properties['conversion_currency'] = strtoupper($currency);
            $properties['conversion_transaction_id'] = data_get($payload, 'data.object.id');
        }

        return $properties;
    }
}

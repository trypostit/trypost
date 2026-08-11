<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;

final class StripeSubscriptionConversion
{
    /**
     * plan_name/interval/persona shared by every PostHog capture backed by a
     * Stripe subscription webhook payload — trial.started, checkout.completed,
     * and trial.converted all start from this shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function baseProperties(Account $account, array $payload): array
    {
        $priceId = data_get($payload, 'data.object.items.data.0.price.id');

        return [
            'plan_name' => $account->plan->name,
            'interval' => $priceId === $account->plan->stripe_yearly_price_id ? 'yearly' : 'monthly',
            'persona' => $account->owner?->persona?->value,
        ];
    }

    /**
     * baseProperties() plus conversion_* fields, for the two events backed by
     * an actual charge: TrackCheckoutCompleted and TrackTrialConverted.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function propertiesFor(Account $account, array $payload): array
    {
        $properties = self::baseProperties($account, $payload);

        $unitAmount = data_get($payload, 'data.object.items.data.0.price.unit_amount');
        $currency = data_get($payload, 'data.object.items.data.0.price.currency');

        if (is_int($unitAmount) && is_string($currency)) {
            $properties['conversion_value'] = (float) ($unitAmount / 100);
            $properties['conversion_currency'] = strtoupper($currency);
            $properties['conversion_transaction_id'] = data_get($payload, 'data.object.id');
        }

        return $properties;
    }
}

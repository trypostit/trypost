<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Billing\Interval;
use App\Models\Account;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use App\Support\Billing\StripeValue;

final class StripeSubscriptionConversion
{
    /**
     * plan_name/interval shared by every PostHog capture backed by a Stripe
     * subscription webhook payload — trial.started, checkout.completed, and
     * trial.converted all start from this shape. Persona is not included
     * here: it is already set as a person property via identify() during
     * welcome, so it is joinable on every event without repeating it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function baseProperties(Account $account, array $payload): array
    {
        return [
            'plan_name' => $account->plan->name,
            'interval' => Interval::forPrice($account->plan, data_get($payload, 'data.object.items.data.0.price.id'))->value,
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

        $currency = StripeValue::currency(data_get($payload, 'data.object.items.data.0.price.currency'));
        $conversionValue = StripeValue::amount(data_get($payload, 'data.object.items.data.0.price.unit_amount'));

        if (filled($conversionValue) && filled($currency)) {
            $properties['conversion_value'] = $conversionValue;
            $properties['conversion_currency'] = $currency;
            $properties['conversion_transaction_id'] = data_get($payload, 'data.object.id');
        }

        return $properties;
    }

    /**
     * propertiesFor() plus the slug of the offer the checkout applied.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function checkoutPropertiesFor(Account $account, array $payload): array
    {
        return [
            ...self::propertiesFor($account, $payload),
            'offer_slug' => self::offerSlug($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function offerSlug(array $payload): ?string
    {
        $slug = data_get($payload, ['data', 'object', 'metadata', ConfigureSubscriptionCheckout::OFFER_SLUG_METADATA_KEY]);

        return is_string($slug) && filled($slug) ? $slug : null;
    }
}

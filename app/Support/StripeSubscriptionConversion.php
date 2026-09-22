<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Illuminate\Support\Carbon;

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
        $priceId = data_get($payload, 'data.object.items.data.0.price.id');
        $yearlyPriceId = $account->plan->stripe_yearly_price_id;
        $isYearly = $yearlyPriceId !== null && $priceId === $yearlyPriceId;

        return [
            'plan_name' => $account->plan->name,
            'interval' => $isYearly ? 'yearly' : 'monthly',
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
        $firstMonthCouponId = self::firstMonthCouponId($payload);

        $unitAmount = data_get($payload, 'data.object.items.data.0.price.unit_amount');
        $currency = data_get($payload, 'data.object.items.data.0.price.currency');

        $properties['is_first_month_offer'] = $firstMonthCouponId !== null;

        if ($firstMonthCouponId !== null) {
            $properties['first_month_coupon_id'] = $firstMonthCouponId;

            $currentPeriodEnd = data_get($payload, 'data.object.items.data.0.current_period_end');

            if (is_int($currentPeriodEnd)) {
                $properties['first_month_offer_ends_at'] = Carbon::createFromTimestamp($currentPeriodEnd)
                    ->toIso8601String();
            }
        }

        if (is_int($unitAmount) && is_string($currency)) {
            $properties['conversion_value'] = (float) ($unitAmount / 100);
            $properties['conversion_currency'] = strtoupper($currency);
            $properties['conversion_transaction_id'] = data_get($payload, 'data.object.id');
        }

        return $properties;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function firstMonthCouponId(array $payload): ?string
    {
        $couponId = data_get(
            $payload,
            'data.object.metadata.'.ConfigureSubscriptionCheckout::FIRST_MONTH_COUPON_METADATA_KEY,
        );

        if (! is_string($couponId) || $couponId === '') {
            return null;
        }

        $configuredCouponIds = collect(config('cashier.first_month_coupon_ids', []))
            ->filter(fn (mixed $configuredCouponId): bool => is_string($configuredCouponId) && $configuredCouponId !== '');

        return $configuredCouponIds->containsStrict($couponId) ? $couponId : null;
    }
}

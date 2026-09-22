<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Stripe\Discount;
use Stripe\Subscription as StripeSubscription;

final class SubscriptionAnalytics
{
    /**
     * @param  array<string, mixed>  $stripeSubscription
     */
    public function syncFromPayload(Subscription $subscription, array $stripeSubscription, ?string $fallbackCouponId = null): void
    {
        $stripeStartedAt = data_get($stripeSubscription, 'start_date')
            ?? data_get($stripeSubscription, 'created');
        $currentPeriodStart = data_get($stripeSubscription, 'items.data.0.current_period_start');
        $currentPeriodEnd = data_get($stripeSubscription, 'items.data.0.current_period_end');
        $couponId = data_get(
            $stripeSubscription,
            'metadata.'.ConfigureSubscriptionCheckout::FIRST_MONTH_COUPON_METADATA_KEY,
            $fallbackCouponId,
        );

        $attributes = [];

        if (is_int($stripeStartedAt)) {
            $attributes['stripe_started_at'] = Carbon::createFromTimestamp($stripeStartedAt);
        }

        if (is_string($couponId) && $couponId !== '') {
            $attributes['first_month_coupon_id'] = $couponId;

            if (
                $subscription->first_month_offer_ends_at === null
                && is_int($stripeStartedAt)
                && is_int($currentPeriodStart)
                && $currentPeriodStart === $stripeStartedAt
                && is_int($currentPeriodEnd)
            ) {
                $attributes['first_month_offer_ends_at'] = Carbon::createFromTimestamp($currentPeriodEnd);
            }
        }

        if ($attributes !== []) {
            $subscription->update($attributes);
        }
    }

    public function syncFromStripe(Subscription $subscription, StripeSubscription $stripeSubscription): void
    {
        $this->syncFromPayload(
            $subscription,
            $stripeSubscription->toArray(),
            $this->configuredCouponId($stripeSubscription),
        );
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function properties(?Subscription $subscription): array
    {
        return [
            'subscription_status' => $subscription?->stripe_status,
            'subscription_started_at' => $subscription?->stripe_started_at?->toIso8601String(),
            'subscription_coupon_id' => $subscription?->first_month_coupon_id,
            'is_in_first_month_offer' => $subscription?->isInFirstMonthOffer() ?? false,
            'first_month_offer_ends_at' => $subscription?->first_month_offer_ends_at?->toIso8601String(),
        ];
    }

    private function configuredCouponId(StripeSubscription $stripeSubscription): ?string
    {
        $configuredCouponIds = collect(config('cashier.first_month_coupon_ids', []))
            ->filter(fn (mixed $couponId): bool => is_string($couponId) && $couponId !== '')
            ->values();

        foreach ($stripeSubscription->discounts ?? [] as $discount) {
            if (! $discount instanceof Discount) {
                continue;
            }

            $couponId = $discount->coupon->id;

            if ($configuredCouponIds->contains($couponId)) {
                return $couponId;
            }
        }

        return null;
    }
}

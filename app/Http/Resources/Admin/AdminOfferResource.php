<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Offer;
use App\Support\Billing\OfferCopy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Offer
 */
class AdminOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $request->user()->timezone;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'stripe_coupon_id' => $this->stripe_coupon_id,
            'trial_days' => $this->trial_days,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'starts_on' => $this->starts_at?->setTimezone($timezone)->toDateString(),
            'ends_on' => $this->ends_at?->setTimezone($timezone)->toDateString(),
            'max_redemptions' => $this->max_redemptions,
            'allow_promotion_codes' => $this->allow_promotion_codes,
            'first_subscription_only' => $this->first_subscription_only,
            'show_banner' => $this->show_banner,
            'requires_card' => $this->requires_card,
            'state' => $this->state()->value,
            'claimed' => (int) $this->accounts_count,
            'redeemed' => (int) $this->redemptions_count,
            'missing_copy' => OfferCopy::missingLocales($this->resource),
        ];
    }
}

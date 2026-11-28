<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Enums\Admin\SubscriptionStatusFilter;
use App\Models\Offer;
use App\Models\Subscription;
use App\Support\Admin\SubscriptionPlans;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class AdminSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->owner?->owner;

        return [
            'id' => $this->id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'plan' => SubscriptionPlans::describe($this->stripe_price),
            'quantity' => (int) $this->quantity,
            'state' => SubscriptionStatusFilter::forSubscription($this->resource, now('UTC'))?->value,
            'stripe_status' => $this->stripe_status,
            'started_at' => $this->created_at?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'offers' => collect($this->owner?->redeemedOffers)->map(fn (Offer $offer): string => $offer->slug)->values()->all(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

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
     * @param  array<string, array{plan: string, name: string, interval: string}>  $prices
     */
    public function __construct(Subscription $resource, private readonly array $prices = [])
    {
        parent::__construct($resource);
    }

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
            'account_name' => $this->owner?->name,
            'plan' => SubscriptionPlans::describe($this->stripe_price, $this->prices),
            'quantity' => (int) $this->quantity,
            'state' => SubscriptionPlans::state($this->resource, now('UTC')),
            'started_at' => $this->created_at?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'offers' => $this->owner?->offers->pluck('slug')->values()->all() ?? [],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;
use Laravel\Cashier\Discount;

/**
 * The coupon on the subscription, so the billing page can say why the
 * invoice is lower than the plan price.
 *
 * @mixin Discount
 */
class SubscriptionDiscountResource extends JsonResource
{
    /**
     * @return array{value: string, duration: string, ends_at: string|null}
     */
    public function toArray(Request $request): array
    {
        $coupon = $this->coupon();

        return [
            'value' => $coupon->isPercentage()
                ? Number::percentage((float) $coupon->percentOff(), maxPrecision: 2, locale: App::getLocale())
                : (string) $coupon->amountOff(),
            'duration' => $coupon->duration(),
            'ends_at' => $this->end()?->toIso8601String(),
        ];
    }
}

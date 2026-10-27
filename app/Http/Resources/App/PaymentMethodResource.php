<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Cashier\PaymentMethod;

/**
 * A card saved on the Stripe customer, as the billing page lists it.
 *
 * @mixin PaymentMethod
 */
class PaymentMethodResource extends JsonResource
{
    public function __construct(PaymentMethod $resource, private bool $isDefault = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, is_default: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand' => $this->card->brand,
            'last4' => $this->card->last4,
            'exp_month' => $this->card->exp_month,
            'exp_year' => $this->card->exp_year,
            'is_default' => $this->isDefault,
        ];
    }
}

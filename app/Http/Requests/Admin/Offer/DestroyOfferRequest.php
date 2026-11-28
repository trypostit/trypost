<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Offer;

use App\Actions\Offer\DeleteOffer;
use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DestroyOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * An offer someone redeemed keeps its history: it can only end.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $offer = $this->route('offer');

                if ($offer instanceof Offer && $offer->redemptions()->exists()) {
                    $validator->errors()->add('offer', DeleteOffer::REDEEMED_MESSAGE);
                }
            },
        ];
    }
}

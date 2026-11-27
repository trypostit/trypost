<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Offer;

use Illuminate\Foundation\Http\FormRequest;

class EndOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ending takes no input: the offer comes from the route.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}

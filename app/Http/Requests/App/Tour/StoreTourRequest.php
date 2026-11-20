<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Tour;

use App\Enums\Tour\Outcome;
use App\Enums\Tour\Tour;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTourRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tour' => ['required', Rule::enum(Tour::class)],
            'outcome' => ['required', Rule::enum(Outcome::class)],
            'steps' => ['required', 'integer', 'min:1'],
            'step' => ['required', 'integer', 'min:1', 'lte:steps'],
        ];
    }
}

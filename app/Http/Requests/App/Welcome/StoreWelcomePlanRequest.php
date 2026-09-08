<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWelcomePlanRequest extends FormRequest
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
            'plan_id' => [
                'required',
                'uuid',
                Rule::exists('plans', 'id')->where(fn ($query) => $query->where('is_archived', false)),
            ],
        ];
    }
}

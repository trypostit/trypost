<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Analytics;

use Illuminate\Foundation\Http\FormRequest;

class ShowAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'publication' => ['sometimes', 'required', 'uuid'],
        ];
    }
}

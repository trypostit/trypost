<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyticsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'end' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }
}

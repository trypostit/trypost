<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnalyticsReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->input('range') === 'custom' ? ['required'] : ['sometimes', 'required'];
        $exclude = Rule::excludeIf(ResolveAnalyticsRangePreset::ignoresDates($this->input('range')));

        return [
            'range' => ['sometimes', Rule::in(ResolveAnalyticsRangePreset::PRESETS)],
            'start' => [$exclude, ...$presence, 'date_format:Y-m-d'],
            'end' => [$exclude, ...$presence, 'date_format:Y-m-d', 'after_or_equal:start'],
        ];
    }
}

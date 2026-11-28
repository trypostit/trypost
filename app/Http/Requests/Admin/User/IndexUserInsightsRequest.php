<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Enums\Analytics\DateRangePreset;
use App\Http\Requests\Admin\Concerns\PicksDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserInsightsRequest extends FormRequest
{
    use PicksDateRange;

    /** How far back a custom range may start, in days. */
    public const int MAX_DAYS_BACK = 730;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::enum(DateRangePreset::class)->only([...DateRangePreset::admin(), DateRangePreset::Custom])],
            'start' => [Rule::requiredIf($this->picksCustomRange()), 'nullable', 'date_format:Y-m-d', "after_or_equal:{$this->earliestDay()}"],
            'end' => [Rule::requiredIf($this->picksCustomRange()), 'nullable', 'date_format:Y-m-d', 'after_or_equal:start', "before_or_equal:{$this->today()}"],
        ];
    }
}

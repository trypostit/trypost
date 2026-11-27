<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Http\Requests\Admin\Concerns\PicksDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserInsightsRequest extends FormRequest
{
    use PicksDateRange;

    /** The fixed ranges the charts offer, next to a custom one. */
    public const array RANGES = ['7d', '30d', '60d', '90d'];

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
            'range' => ['nullable', Rule::in([...self::RANGES, 'custom'])],
            'start' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', "after_or_equal:{$this->earliestDay()}"],
            'end' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start', "before_or_equal:{$this->today()}"],
        ];
    }
}

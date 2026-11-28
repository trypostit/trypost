<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Quota;

use App\Enums\Analytics\DateRangePreset;
use App\Http\Requests\Admin\Concerns\PicksDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexQuotaRequest extends FormRequest
{
    use PicksDateRange;

    /**
     * How far back the period's bounds reach, in days: X keeps 90 days of
     * usage. The page offers the fixed periods only, never a custom range.
     */
    public const int MAX_DAYS_BACK = 90;

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
            'range' => ['nullable', Rule::enum(DateRangePreset::class)->only(DateRangePreset::backoffice())],
        ];
    }

    /** X counts and bills its usage by UTC days, so its charts do too. */
    public function timezone(): string
    {
        return 'UTC';
    }
}

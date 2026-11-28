<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Subscription;

use App\Enums\Admin\SubscriptionStatusFilter;
use App\Enums\Analytics\DateRangePreset;
use App\Http\Requests\Admin\Concerns\PicksDateRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSubscriptionRequest extends FormRequest
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
            'range' => ['nullable', Rule::enum(DateRangePreset::class)->only([...DateRangePreset::backoffice(), DateRangePreset::Custom])],
            'start' => [Rule::requiredIf($this->picksCustomRange()), 'nullable', 'date_format:Y-m-d', "after_or_equal:{$this->earliestDay()}"],
            'end' => [Rule::requiredIf($this->picksCustomRange()), 'nullable', 'date_format:Y-m-d', 'after_or_equal:start', "before_or_equal:{$this->today()}"],
            'status' => ['nullable', Rule::enum(SubscriptionStatusFilter::class)],
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function status(): SubscriptionStatusFilter
    {
        return $this->enum('status', SubscriptionStatusFilter::class) ?? SubscriptionStatusFilter::All;
    }

    public function search(): ?string
    {
        return $this->validated('search');
    }
}

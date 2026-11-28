<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Post;

use App\Enums\Analytics\DateRangePreset;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\Admin\Concerns\PicksDateRange;
use App\Support\Admin\AdminNetworks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPostStatsRequest extends FormRequest
{
    use PicksDateRange;

    /** How far back a custom range may start, in days. */
    public const int MAX_DAYS_BACK = 365;

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
            'networks' => ['nullable', 'array'],
            'networks.*' => ['string', Rule::in(AdminNetworks::options())],
        ];
    }

    /**
     * @return list<string>
     */
    public function networks(): array
    {
        return collect($this->validated('networks', []))->values()->all();
    }

    /**
     * @return list<Platform>
     */
    public function platforms(): array
    {
        return AdminNetworks::platformsFor($this->networks());
    }
}

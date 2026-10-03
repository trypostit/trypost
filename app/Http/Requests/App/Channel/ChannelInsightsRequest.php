<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Support\Analytics\ChannelMetrics;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChannelInsightsRequest extends FormRequest
{
    use EnsuresChannelInCurrentWorkspace;

    public function authorize(): bool
    {
        $this->ensureCurrentWorkspace($this, $this->route('account'));

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $exclude = Rule::excludeIf(ResolveAnalyticsRangePreset::ignoresDates($this->input('range')));

        return [
            'range' => ['sometimes', Rule::in(ResolveAnalyticsRangePreset::PRESETS)],
            'start' => [$exclude, 'required_if:range,custom', 'date_format:Y-m-d'],
            'end' => [$exclude, 'required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:start'],
            'period' => ['sometimes', Rule::in(['current', 'previous'])],
            'sort' => ['sometimes', Rule::in(ChannelMetrics::sortable())],
        ];
    }
}

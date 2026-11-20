<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Dto\Analytics\PublicationFilter;
use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Models\SocialAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DownloadChannelInsightsRequest extends FormRequest
{
    use EnsuresChannelInCurrentWorkspace;

    public function authorize(): bool
    {
        $this->ensureCurrentWorkspace($this, $this->channel());

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
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['uuid'],
            'untagged' => ['sometimes', 'boolean'],
            'types' => ['sometimes', 'array'],
            'types.*' => [Rule::in(PublicationFilter::typesFor($this->channel()->platform))],
        ];
    }

    public function publicationFilter(): PublicationFilter
    {
        return PublicationFilter::fromValidated($this->channel()->platform, $this->validated());
    }

    private function channel(): SocialAccount
    {
        return $this->route('account');
    }
}

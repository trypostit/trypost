<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Dto\Analytics\PublicationFilter;
use App\Enums\Analytics\PdfSection;
use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Models\SocialAccount;
use App\Models\WorkspaceLabel;
use App\Support\Analytics\ChannelMetrics;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asks for a PDF of one channel's Insights with the filters and sort the page
 * shows, plus the report name, cover logo, sections and the post table columns.
 */
class StoreChannelInsightsPdfRequest extends FormRequest
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
            'labels.*' => ['bail', 'uuid', Rule::exists(WorkspaceLabel::class, 'id')
                ->where('workspace_id', $this->user()->current_workspace_id)
                ->withoutTrashed()],
            'untagged' => ['sometimes', 'boolean'],
            'types' => ['sometimes', 'array'],
            'types.*' => [Rule::in(PublicationFilter::typesFor($this->channel()->platform))],
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(ChannelMetrics::SORTS))],
            'name' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => [Rule::in(PdfSection::values(PdfSection::forChannel()))],
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sections.required' => __('analytics.insights.export.pdf.sections_required'),
            'sections.min' => __('analytics.insights.export.pdf.sections_required'),
        ];
    }

    private function channel(): SocialAccount
    {
        return $this->route('account');
    }
}

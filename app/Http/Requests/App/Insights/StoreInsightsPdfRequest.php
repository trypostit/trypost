<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Insights;

use App\Actions\Analytics\BuildPublicationAnalyticsReport;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Enums\Analytics\PdfSection;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asks for a PDF of the workspace Insights with the filters the page shows, plus
 * the report name, cover logo, sections and the Performance columns and sort.
 */
class StoreInsightsPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $presence = $this->input('range') === 'custom' ? ['required'] : ['sometimes', 'required'];
        $exclude = Rule::excludeIf(ResolveAnalyticsRangePreset::ignoresDates($this->input('range')));

        return [
            'range' => ['sometimes', Rule::in(ResolveAnalyticsRangePreset::PRESETS)],
            'start' => [$exclude, ...$presence, 'date_format:Y-m-d'],
            'end' => [$exclude, ...$presence, 'date_format:Y-m-d', 'after_or_equal:start'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['string'],
            'name' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => [Rule::in(PdfSection::values(PdfSection::forWorkspace()))],
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string', 'max:64'],
            'sort' => ['sometimes', Rule::in(['channel', ...BuildPublicationAnalyticsReport::PERFORMANCE_METRICS])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
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
}

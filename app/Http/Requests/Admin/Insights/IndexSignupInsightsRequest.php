<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Insights;

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSignupInsightsRequest extends FormRequest
{
    /** The fixed ranges the charts offer, next to a custom one. */
    public const array RANGES = ['7d', '30d', '60d', '90d'];

    public const string DEFAULT_RANGE = '30d';

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
            'start' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.self::earliest($this->timezone())->toDateString()],
            'end' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start', 'before_or_equal:today'],
        ];
    }

    public function range(): string
    {
        return (string) ($this->validated('range') ?? self::DEFAULT_RANGE);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function dates(): array
    {
        return app(ResolveAnalyticsRangePreset::class)->handle(
            $this->range(),
            $this->validated('start'),
            $this->validated('end'),
            $this->timezone(),
        );
    }

    /** The viewing user's zone, whose calendar days the page counts in. */
    public function timezone(): string
    {
        return (string) ($this->user()?->timezone ?? 'UTC');
    }

    public static function earliest(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)->startOfDay()->subDays(self::MAX_DAYS_BACK);
    }
}

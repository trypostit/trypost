<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Network;

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexNetworkHealthRequest extends FormRequest
{
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
            'range' => ['nullable', Rule::in(ResolveAnalyticsRangePreset::PRESETS)],
            'start' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.self::earliest($this->timezone())->toDateString()],
            'end' => ['required_if:range,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start', 'before_or_equal:today'],
        ];
    }

    public function range(): string
    {
        return (string) ($this->validated('range') ?? ResolveAnalyticsRangePreset::DEFAULT);
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

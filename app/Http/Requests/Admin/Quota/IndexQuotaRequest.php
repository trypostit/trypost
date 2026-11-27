<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Quota;

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexQuotaRequest extends FormRequest
{
    /** The ranges the X tab offers: X keeps 90 days of usage, so no custom range. */
    public const array RANGES = ['7d', '30d', '60d', '90d'];

    public const string DEFAULT_RANGE = '30d';

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
            'network' => ['nullable', Rule::in(['x', 'youtube'])],
            'range' => ['nullable', Rule::in(self::RANGES)],
        ];
    }

    public function range(): string
    {
        return (string) ($this->validated('range') ?? self::DEFAULT_RANGE);
    }

    /**
     * The UTC days the X charts cover.
     *
     * @return array{start: string, end: string}
     */
    public function dates(): array
    {
        return app(ResolveAnalyticsRangePreset::class)->handle($this->range(), null, null, 'UTC');
    }
}

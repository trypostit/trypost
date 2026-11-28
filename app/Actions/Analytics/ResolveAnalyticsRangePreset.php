<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Enums\Analytics\DateRangePreset;
use Illuminate\Support\Arr;

class ResolveAnalyticsRangePreset
{
    /** The periods the app's insights, the API and MCP offer. */
    public const array PRESETS = [
        DateRangePreset::SevenDays->value,
        DateRangePreset::ThirtyDays->value,
        DateRangePreset::MonthToDate->value,
        DateRangePreset::Custom->value,
    ];

    public const string DEFAULT = DateRangePreset::DEFAULT_RANGE->value;

    public static function ignoresDates(mixed $range): bool
    {
        return is_string($range) && $range !== '' && $range !== 'custom';
    }

    /**
     * @param  array{range?: ?string, start?: ?string, end?: ?string}  $validated
     * @return array{range: string, selection: array{start?: string, end?: string, observed_through?: string, timezone: string}, clamped: bool}
     */
    public function selection(array $validated, string $timezone): array
    {
        $selection = array_filter(Arr::only($validated, ['start', 'end']), fn (mixed $date): bool => $date !== null);
        $range = data_get($validated, 'range') ?? ($selection !== [] ? 'custom' : self::DEFAULT);

        if ($range !== 'custom') {
            $selection = DateRangePreset::from($range)->dates($timezone);
        }

        if ($range !== 'custom' && data_get($selection, 'end') === now($timezone)->toDateString()) {
            $selection['observed_through'] = now('UTC')->toDateString();
        }

        $selection['timezone'] = $timezone;

        return ['range' => $range, 'selection' => $selection, 'clamped' => $range === 'custom'];
    }
}

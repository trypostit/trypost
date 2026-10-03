<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use DateTimeInterface;
use Illuminate\Support\Arr;

class ResolveAnalyticsRangePreset
{
    public const array PRESETS = ['7d', '30d', 'mtd', 'last_month', 'custom'];

    public const string DEFAULT = '30d';

    public static function ignoresDates(mixed $range): bool
    {
        return is_string($range) && $range !== '' && $range !== 'custom';
    }

    /**
     * @param  array{range?: ?string, start?: ?string, end?: ?string}  $validated
     * @return array{range: string, selection: array{start?: string, end?: string, observed_through?: string}, clamped: bool}
     */
    public function selection(array $validated, string $timezone): array
    {
        $selection = array_filter(Arr::only($validated, ['start', 'end']), fn (mixed $date): bool => $date !== null);
        $range = data_get($validated, 'range') ?? ($selection !== [] ? 'custom' : self::DEFAULT);

        if ($range !== 'custom') {
            $selection = $this->handle($range, null, null, $timezone);
        }

        if ($range !== 'custom' && data_get($selection, 'end') === now($timezone)->toDateString()) {
            $selection['observed_through'] = now('UTC')->toDateString();
        }

        return ['range' => $range, 'selection' => $selection, 'clamped' => $range === 'custom'];
    }

    /**
     * @return array{start: string, end: string}
     */
    public function handle(string $range, ?string $start, ?string $end, string $timezone): array
    {
        $today = now($timezone)->startOfDay();

        return match ($range) {
            '7d' => $this->between($today->subDays(6), $today),
            '30d' => $this->between($today->subDays(29), $today),
            'mtd' => $this->between($today->startOfMonth(), $today),
            'last_month' => $this->between(
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth(),
            ),
            default => ['start' => (string) $start, 'end' => (string) $end],
        };
    }

    /**
     * @return array{start: string, end: string}
     */
    private function between(DateTimeInterface $start, DateTimeInterface $end): array
    {
        return ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }
}

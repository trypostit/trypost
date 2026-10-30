<?php

declare(strict_types=1);

namespace App\Support\Analytics\Pdf;

use Carbon\CarbonImmutable;
use Illuminate\Support\Number;

/**
 * Formats Insights numbers and dates for the PDF the way the Insights page shows
 * them, in the language the report is rendered in.
 */
class PdfFormat
{
    public function __construct(private readonly string $locale) {}

    public function metric(int|float|null $value, string $metric): string
    {
        if ($value === null) {
            return '—';
        }

        return $metric === 'engagement_rate' ? $this->percent($value) : $this->compact($value);
    }

    public function compact(int|float $value): string
    {
        return Number::withLocale($this->locale, fn (): string => abs($value) < 1000
            ? (string) Number::format($value, maxPrecision: 2)
            : (string) Number::abbreviate($value, maxPrecision: 1));
    }

    /** A whole number with grouping, for axes whose values sit close together. */
    public function number(int|float $value): string
    {
        return Number::withLocale($this->locale, fn (): string => (string) Number::format($value, maxPrecision: 0));
    }

    public function percent(int|float $value): string
    {
        return Number::withLocale($this->locale, fn (): string => (string) Number::percentage($value, maxPrecision: 2));
    }

    /**
     * The change next to a value: a percentage, except followers, which change by a count.
     *
     * @return array{label: string, direction: string}|null
     */
    public function change(int|float|null $change, string $metric): ?array
    {
        if ($change === null) {
            return null;
        }

        $sign = $change > 0 ? '+' : ($change < 0 ? '-' : '');
        $size = abs($change);
        $label = $metric === 'followers'
            ? $this->compact($size)
            : ($size >= 1000 ? Number::withLocale($this->locale, fn (): string => (string) Number::abbreviate($size, maxPrecision: 1)).'%' : $this->percent($size));

        return [
            'label' => "{$sign}{$label}",
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'flat'),
        ];
    }

    public function day(string $date, string $timezone): string
    {
        return CarbonImmutable::parse($date)->setTimezone($timezone)->locale($this->locale)->isoFormat('D MMM YYYY');
    }

    /**
     * @param  array{start: string, end: string}|null  $range
     */
    public function span(?array $range): string
    {
        if ($range === null) {
            return '';
        }

        $format = fn (string $date): string => CarbonImmutable::parse($date)->locale($this->locale)->isoFormat('D MMM YYYY');

        return $format((string) data_get($range, 'start')).' – '.$format((string) data_get($range, 'end'));
    }

    public function shortDay(string $date): string
    {
        return CarbonImmutable::parse($date)->locale($this->locale)->isoFormat('D MMM');
    }
}

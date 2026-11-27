<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Dto\Analytics\DateRange;

/**
 * The period a backoffice page covers: a preset or a custom range of the
 * viewing admin's calendar days. The request using it declares its rules
 * and its `MAX_DAYS_BACK`, how far back a custom range may start.
 */
trait PicksDateRange
{
    public const string DEFAULT_RANGE = ResolveAnalyticsRangePreset::DEFAULT;

    public function range(): string
    {
        return (string) ($this->validated('range') ?? self::DEFAULT_RANGE);
    }

    /**
     * The calendar days the page covers, in the viewing admin's zone, first and last.
     *
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

    /** The days the page covers as a range of the viewing admin's zone. */
    public function dateRange(): DateRange
    {
        $dates = $this->dates();

        return DateRange::fromDates($dates['start'], $dates['end'], $this->timezone());
    }

    /** The viewing admin's zone, whose calendar days the page counts in. */
    public function timezone(): string
    {
        return $this->user()->timezone;
    }

    /** The admin's today, the last day a custom range may end on. */
    public function today(): string
    {
        return now($this->timezone())->toDateString();
    }

    /** The first day a custom range may start on. */
    public function earliestDay(): string
    {
        return now($this->timezone())->startOfDay()->subDays(static::MAX_DAYS_BACK)->toDateString();
    }

    /**
     * The period's props every range page sends: the days covered and the
     * bounds of the custom picker.
     *
     * @return array{range: array{start: string, end: string}, bounds: array{min: string, max: string}}
     */
    public function rangeProps(): array
    {
        return [
            'range' => $this->dates(),
            'bounds' => [
                'min' => $this->earliestDay(),
                'max' => $this->today(),
            ],
        ];
    }

    /**
     * The period's part of the page filters.
     *
     * @return array{range: string, start: string, end: string}
     */
    public function rangeFilters(): array
    {
        $dates = $this->dates();

        return [
            'range' => $this->range(),
            'start' => $dates['start'],
            'end' => $dates['end'],
        ];
    }
}

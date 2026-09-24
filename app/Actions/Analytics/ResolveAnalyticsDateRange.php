<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use Carbon\CarbonImmutable;

class ResolveAnalyticsDateRange
{
    /**
     * @param  array{min: ?string, max: ?string}  $bounds
     * @param  array{start?: string, end?: string}  $selected
     */
    public function execute(array $bounds, array $selected): DateRange
    {
        $minimumDate = data_get($bounds, 'min');
        $maximumDate = data_get($bounds, 'max');
        $selectedStart = data_get($selected, 'start');
        $selectedEnd = data_get($selected, 'end');
        $end = $maximumDate ? CarbonImmutable::parse($maximumDate, 'UTC') : CarbonImmutable::today('UTC');
        $start = $end->subDays(29);

        if ($minimumDate !== null && $selectedStart !== null) {
            $start = CarbonImmutable::parse($selectedStart, 'UTC');
        }

        if ($maximumDate !== null && $selectedEnd !== null) {
            $end = CarbonImmutable::parse($selectedEnd, 'UTC');
        }

        if ($minimumDate !== null && $maximumDate !== null) {
            $minimum = CarbonImmutable::parse($minimumDate, 'UTC');
            $maximum = CarbonImmutable::parse($maximumDate, 'UTC');
            $start = $start->lessThan($minimum) ? $minimum : ($start->greaterThan($maximum) ? $maximum : $start);
            $end = $end->lessThan($minimum) ? $minimum : ($end->greaterThan($maximum) ? $maximum : $end);
        }

        return new DateRange($start->greaterThan($end) ? $end : $start, $end);
    }
}

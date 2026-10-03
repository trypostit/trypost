<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Dto\Analytics\DateRange;
use App\Enums\User\WeekStart;

class PeriodBuckets
{
    public function resolution(DateRange $range): string
    {
        return match (true) {
            $range->days() <= 14 => 'daily',
            $range->days() <= 90 => 'weekly',
            default => 'monthly',
        };
    }

    /** @return list<array{start: string, end: string}> */
    public function for(DateRange $range, WeekStart $weekStart): array
    {
        $resolution = $this->resolution($range);
        $cursor = $range->start;
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($range->end)) {
            $boundary = match ($resolution) {
                'weekly' => $cursor->endOfWeek($weekStart->lastDay()),
                'monthly' => $cursor->endOfMonth(),
                default => $cursor,
            };
            $last = $boundary->lessThan($range->end) ? $boundary : $range->end;
            $buckets[] = [
                'start' => $cursor->toDateString(),
                'end' => $last->toDateString(),
            ];
            $cursor = $last->addDay()->startOfDay();
        }

        return $buckets;
    }
}

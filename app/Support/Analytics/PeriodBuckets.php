<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Dto\Analytics\DateRange;
use Carbon\CarbonImmutable;

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

    /** @return list<array{start: string, end: string, label: string}> */
    public function for(DateRange $range): array
    {
        $resolution = $this->resolution($range);
        $cursor = $range->start;
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($range->end)) {
            $boundary = match ($resolution) {
                'weekly' => $cursor->endOfWeek(),
                'monthly' => $cursor->endOfMonth(),
                default => $cursor,
            };
            $last = $boundary->lessThan($range->end) ? $boundary : $range->end;
            $buckets[] = [
                'start' => $cursor->toDateString(),
                'end' => $last->toDateString(),
                'label' => $resolution === 'monthly'
                    ? $cursor->format('M Y')
                    : $this->label($cursor, $last),
            ];
            $cursor = $last->addDay()->startOfDay();
        }

        return $buckets;
    }

    private function label(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->isSameDay($end)
            ? $start->format('M j')
            : $start->format('M j').' – '.$end->format('M j');
    }
}

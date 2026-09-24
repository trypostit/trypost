<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class DateRange
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(CarbonImmutable $start, CarbonImmutable $end)
    {
        $this->start = $start->utc()->startOfDay();
        $this->end = $end->utc()->startOfDay();

        if ($this->start->greaterThan($this->end)) {
            throw new InvalidArgumentException('The analytics date range must start before it ends.');
        }
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end->addDay());
    }

    public function previous(): self
    {
        $end = $this->start->subDay();

        return new self($this->start->subDays($this->days()), $end);
    }

    /** @return array{start: string, end: string} */
    public function toArray(): array
    {
        return ['start' => $this->start->toDateString(), 'end' => $this->end->toDateString()];
    }
}

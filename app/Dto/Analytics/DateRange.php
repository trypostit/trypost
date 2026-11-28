<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class DateRange
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    /**
     * The last observation date that belongs to this range. Account snapshots are dated by the network's
     * UTC day, so when the range ends on the viewer's today and the UTC day has already rolled over, today's
     * snapshots carry the next UTC date and still belong to the range's last day.
     */
    public CarbonImmutable $observedThrough;

    /**
     * The viewer's time zone. Start and end are calendar days in it; publications are counted on the day
     * their instant falls on there, while account snapshots keep the network's calendar date.
     */
    public string $timezone;

    public function __construct(CarbonImmutable $start, CarbonImmutable $end, ?CarbonImmutable $observedThrough = null, string $timezone = 'UTC')
    {
        $this->start = $start->utc()->startOfDay();
        $this->end = $end->utc()->startOfDay();
        $observedThrough = $observedThrough?->utc()->startOfDay();
        $this->observedThrough = $observedThrough?->greaterThan($this->end) ? $observedThrough : $this->end;
        $this->timezone = $timezone;

        if ($this->start->greaterThan($this->end)) {
            throw new InvalidArgumentException('The analytics date range must start before it ends.');
        }
    }

    /**
     * The range between two calendar days of the viewer's zone (`Y-m-d`), both included.
     */
    public static function fromDates(string $start, string $end, string $timezone): self
    {
        return new self(CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC'), timezone: $timezone);
    }

    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end->addDay());
    }

    public function previous(): self
    {
        $end = $this->start->subDay();

        return new self($this->start->subDays($this->days()), $end, timezone: $this->timezone);
    }

    /**
     * Every calendar day of the range, first to last, as `Y-m-d`.
     *
     * @return list<string>
     */
    public function calendarDays(): array
    {
        return Collection::range(0, $this->days() - 1)
            ->map(fn (int $offset): string => $this->start->addDays($offset)->toDateString())
            ->all();
    }

    /**
     * Each of the range's days' count, first to last, from counts keyed by
     * calendar day (`Y-m-d`); a day without one counts zero.
     *
     * @param  Collection<string, int>  $countsByDate
     * @return list<int>
     */
    public function perDay(Collection $countsByDate): array
    {
        return collect($this->calendarDays())
            ->map(fn (string $day): int => (int) $countsByDate->get($day, 0))
            ->all();
    }

    /** The UTC instant the range's first day begins in the viewer's zone. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start->toDateString(), $this->timezone)->startOfDay()->utc();
    }

    /** The UTC instant the range's last day ends in the viewer's zone. */
    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->end->toDateString(), $this->timezone)->endOfDay()->utc();
    }

    /** The viewer's calendar day of a UTC instant. */
    public function localDate(DateTimeInterface|string $instant): string
    {
        return CarbonImmutable::parse($instant, 'UTC')->setTimezone($this->timezone)->toDateString();
    }

    public function contains(DateTimeInterface|string $instant): bool
    {
        $day = $this->localDate($instant);

        return $day >= $this->start->toDateString() && $day <= $this->end->toDateString();
    }

    /** @return array{start: string, end: string} */
    public function toArray(): array
    {
        return ['start' => $this->start->toDateString(), 'end' => $this->end->toDateString()];
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Generator;
use InvalidArgumentException;

/**
 * A channel's recurring weekly posting times, local to the channel time zone.
 * Always seven days ordered Sunday (0) to Saturday (6); each day keeps its
 * times while switched off so switching it back on restores them.
 */
final class PostingSchedule
{
    public const MAX_TIMES_PER_DAY = 4;

    public const MAX_GOAL = 28;

    public const MAX_INSTANT = '2037-12-31 23:59:59';

    /**
     * @param  list<array{day: int, enabled: bool, times: list<string>}>  $days
     */
    private function __construct(private readonly array $days) {}

    public static function empty(): self
    {
        return new self(array_map(
            fn (int $day): array => ['day' => $day, 'enabled' => true, 'times' => []],
            range(0, 6),
        ));
    }

    /**
     * @param  array<int, array{day?: mixed, enabled?: mixed, times?: mixed}>  $days
     */
    public static function fromArray(array $days): self
    {
        if (count($days) !== 7) {
            throw new InvalidArgumentException('A posting schedule has exactly seven days.');
        }

        $byDay = [];

        foreach ($days as $entry) {
            $day = data_get($entry, 'day');

            if (! is_int($day) || $day < 0 || $day > 6 || array_key_exists($day, $byDay)) {
                throw new InvalidArgumentException('Invalid or duplicate day.');
            }

            $times = data_get($entry, 'times', []);

            if (! is_array($times)) {
                throw new InvalidArgumentException('Times must be a list.');
            }

            foreach ($times as $time) {
                self::assertTime($time);
            }

            $times = array_values(array_unique($times));
            sort($times);

            if (count($times) > self::MAX_TIMES_PER_DAY) {
                throw new InvalidArgumentException('Too many times for one day.');
            }

            $byDay[$day] = ['day' => $day, 'enabled' => (bool) data_get($entry, 'enabled', true), 'times' => $times];
        }

        ksort($byDay);

        return new self(array_values($byDay));
    }

    /**
     * @return list<array{day: int, enabled: bool, times: list<string>}>
     */
    public function days(): array
    {
        return $this->days;
    }

    /**
     * @return list<array{day: int, enabled: bool, times: list<string>}>
     */
    public function toArray(): array
    {
        return $this->days;
    }

    public function withTime(int $day, string $time): self
    {
        self::assertTime($time);
        $times = $this->days[$day]['times'];

        if (in_array($time, $times, true)) {
            return $this;
        }

        if (count($times) >= self::MAX_TIMES_PER_DAY) {
            throw new InvalidArgumentException('Too many times for one day.');
        }

        $times[] = $time;
        sort($times);

        return $this->replaceDay($day, ['times' => $times]);
    }

    public function withoutTime(int $day, string $time): self
    {
        return $this->replaceDay($day, [
            'times' => array_values(array_filter($this->days[$day]['times'], fn (string $t): bool => $t !== $time)),
        ]);
    }

    public function withDayEnabled(int $day, bool $enabled): self
    {
        return $this->replaceDay($day, ['enabled' => $enabled]);
    }

    public function cleared(): self
    {
        return new self(array_map(fn (array $d): array => [...$d, 'times' => []], $this->days));
    }

    public function slotCount(): int
    {
        return array_sum(array_map(
            fn (array $d): int => $d['enabled'] ? count($d['times']) : 0,
            $this->days,
        ));
    }

    /**
     * The next `$count` enabled slots strictly after `$after`, as UTC instants,
     * computed in the channel time zone (DST gaps shift forward, overlaps take the
     * first occurrence; a day's shifted times are re-sorted and deduplicated).
     *
     * @return list<CarbonImmutable>
     */
    public function nextSlots(CarbonInterface $after, string $timezone, int $count): array
    {
        if ($count <= 0 || $this->slotCount() === 0) {
            return [];
        }

        $ceiling = CarbonImmutable::parse(self::MAX_INSTANT, 'UTC');
        $afterUtc = CarbonImmutable::instance($after)->utc();
        $day = CarbonImmutable::instance($after)->setTimezone($timezone)->startOfDay();
        $slots = [];

        while (count($slots) < $count) {
            $entry = $this->days[$day->dayOfWeek];

            if ($entry['enabled']) {
                $instants = [];

                foreach ($entry['times'] as $time) {
                    [$hour, $minute] = array_map('intval', explode(':', $time));
                    $local = CarbonImmutable::create($day->year, $day->month, $day->day, $hour, $minute, 0, $timezone);
                    $earlier = $local->subHour();
                    $instant = ($earlier->hour === $hour && $earlier->minute === $minute ? $earlier : $local)->utc();
                    $instants[$instant->getTimestamp()] = $instant;
                }

                ksort($instants);

                foreach ($instants as $instant) {
                    if ($instant->greaterThan($ceiling)) {
                        return $slots;
                    }

                    if ($instant->greaterThan($afterUtc)) {
                        $slots[] = $instant;

                        if (count($slots) === $count) {
                            return $slots;
                        }
                    }
                }
            }

            $day = $day->addDay();

            if ($day->utc()->greaterThan($ceiling)) {
                return $slots;
            }
        }

        return $slots;
    }

    public function hasSlotAt(CarbonInterface $instant, string $timezone): bool
    {
        $slot = $this->nextSlots(CarbonImmutable::instance($instant)->subSecond(), $timezone, 1)[0] ?? null;

        return $slot !== null && $slot->equalTo($instant);
    }

    /**
     * Every enabled slot strictly after `$after`, lazily, as UTC instants.
     *
     * @return Generator<int, CarbonImmutable>
     */
    public function slotsAfter(CarbonInterface $after, string $timezone): Generator
    {
        $cursor = $after;

        while (true) {
            $batch = $this->nextSlots($cursor, $timezone, 64);

            foreach ($batch as $slot) {
                yield $slot;
            }

            if (count($batch) < 64) {
                return;
            }

            $cursor = end($batch);
        }
    }

    /**
     * Every enabled slot strictly after `$after` and at or before `$until`, as UTC instants.
     *
     * @return list<CarbonImmutable>
     */
    public function slotsBetween(CarbonInterface $after, CarbonInterface $until, string $timezone): array
    {
        $slots = [];

        if ($until->lessThanOrEqualTo($after)) {
            return $slots;
        }

        foreach ($this->slotsAfter($after, $timezone) as $slot) {
            if ($slot->greaterThan($until)) {
                break;
            }

            $slots[] = $slot;
        }

        return $slots;
    }

    /**
     * @param  array{enabled?: bool, times?: list<string>}  $changes
     */
    private function replaceDay(int $day, array $changes): self
    {
        if ($day < 0 || $day > 6) {
            throw new InvalidArgumentException('Invalid day.');
        }

        $days = $this->days;
        $days[$day] = [...$days[$day], ...$changes];

        return new self($days);
    }

    private static function assertTime(mixed $time): void
    {
        if (! is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new InvalidArgumentException('Times must be HH:MM.');
        }
    }
}

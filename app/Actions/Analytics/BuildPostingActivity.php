<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Dto\Analytics\PublicationFilter;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Posts that went out per day over the last year, for the Insights posting activity card.
 *
 * Counts the same publications as the posts chart (`BuildPublicationAnalyticsReport::query()`),
 * on calendar days of the viewer's zone, whatever period the page shows.
 */
final class BuildPostingActivity
{
    public const int DAYS = 365;

    public function __construct(private readonly BuildPublicationAnalyticsReport $publications) {}

    /**
     * @param  list<string>|null  $accountKeys  Null counts every analytics account of the workspace.
     * @return array{start: string, end: string, days: list<array{date: string, count: int}>, total: int, max_count: int, current_streak: int, best_streak: int}
     */
    public function execute(Workspace $workspace, ?array $accountKeys, string $timezone, ?PublicationFilter $filter = null): array
    {
        $today = CarbonImmutable::parse(now($timezone)->toDateString(), 'UTC');
        $range = new DateRange($today->subDays(self::DAYS - 1), $today, timezone: $timezone);
        $counts = [];

        for ($day = $range->start; $day->lessThanOrEqualTo($range->end); $day = $day->addDay()) {
            $counts[$day->toDateString()] = 0;
        }

        $published = $this->publications->query($workspace, $range->startsAt(), $range->endsAt(), $accountKeys, $filter)
            ->select('publication.provider_published_at')
            ->cursor();

        foreach ($published as $row) {
            $date = $range->localDate($row->provider_published_at);

            if (array_key_exists($date, $counts)) {
                $counts[$date]++;
            }
        }

        $days = collect($counts)
            ->map(fn (int $count, string $date): array => ['date' => $date, 'count' => $count])
            ->values()
            ->all();
        $streaks = self::streaks($days);

        return [
            'start' => $range->start->toDateString(),
            'end' => $range->end->toDateString(),
            'days' => $days,
            'total' => array_sum($counts),
            'max_count' => max($counts),
            'current_streak' => data_get($streaks, 'current'),
            'best_streak' => data_get($streaks, 'best'),
        ];
    }

    /**
     * Runs of days with at least one post. The current run ends on the last day,
     * or on the day before when the last day has no post yet.
     *
     * @param  list<array{date: string, count: int}>  $days  Chronological, one entry per day.
     * @return array{current: int, best: int}
     */
    public static function streaks(array $days): array
    {
        $best = 0;
        $run = 0;

        foreach ($days as $day) {
            $run = data_get($day, 'count') > 0 ? $run + 1 : 0;
            $best = max($best, $run);
        }

        return ['current' => self::currentStreak($days), 'best' => $best];
    }

    /**
     * @param  list<array{date: string, count: int}>  $days
     */
    private static function currentStreak(array $days): int
    {
        $remaining = $days;

        if (data_get(end($remaining), 'count', 0) === 0) {
            array_pop($remaining);
        }

        $current = 0;

        foreach (array_reverse($remaining) as $day) {
            if (data_get($day, 'count') === 0) {
                return $current;
            }

            $current++;
        }

        return $current;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\DateRange;
use App\Dto\Analytics\PublicationFilter;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

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

        $published = $this->publications->query($workspace, $range->startsAt(), $range->endsAt(), $accountKeys, $filter)
            ->select('publication.provider_published_at')
            ->cursor()
            ->countBy(fn (object $row): string => $range->localDate($row->provider_published_at));

        $days = collect(CarbonPeriod::create($range->start, $range->end))
            ->map(fn (CarbonInterface $day): array => [
                'date' => $day->toDateString(),
                'count' => (int) $published->get($day->toDateString(), 0),
            ])
            ->values();
        $streaks = self::streaks($days->all());

        return [
            'start' => $range->start->toDateString(),
            'end' => $range->end->toDateString(),
            'days' => $days->all(),
            'total' => $days->sum('count'),
            'max_count' => $days->max('count'),
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

        return ['current' => self::currentStreak(collect($days)), 'best' => $best];
    }

    /**
     * @param  Collection<int, array{date: string, count: int}>  $days
     */
    private static function currentStreak(Collection $days): int
    {
        if (data_get($days->last(), 'count', 0) === 0) {
            $days = $days->slice(0, -1);
        }

        return $days->reverse()
            ->takeWhile(fn (array $day): bool => data_get($day, 'count') > 0)
            ->count();
    }
}

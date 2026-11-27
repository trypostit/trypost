<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;

class BuildChannelGrowth
{
    /**
     * The channels connected per calendar day of the viewer's zone over the range, per network, by
     * the day each was first connected (a reconnect updates the same row).
     * Disconnecting deletes a channel, so a channel removed since is not
     * counted: these are the connections that are still here. The total per
     * day is rebuilt the same way, from the channels still here, so it never
     * shows a day the total went down.
     *
     * @return array{days: list<string>, by_network: array<string, list<int>>, total_by_day: list<int>, new_in_range: int, connected_now: int}
     */
    public static function execute(DateRange $range): array
    {
        $days = $range->calendarDays();

        $connections = SocialAccount::query()
            ->whereBetween('created_at', [$range->startsAt(), $range->endsAt()])
            ->toBase()
            ->get(['platform', 'created_at'])
            ->map(fn (object $channel): array => [
                'network' => Platform::from((string) $channel->platform)->network(),
                'date' => $range->localDate($channel->created_at),
            ]);

        $newPerDay = $connections->countBy('date');
        $total = SocialAccount::query()->where('created_at', '<', $range->startsAt())->count();

        return [
            'days' => $days,
            'total_by_day' => collect($days)
                ->map(function (string $day) use (&$total, $newPerDay): int {
                    $total += (int) $newPerDay->get($day, 0);

                    return $total;
                })
                ->all(),
            'by_network' => $connections
                ->groupBy('network')
                ->map(function (Collection $networkConnections) use ($days): array {
                    $perDay = $networkConnections->countBy('date');

                    return collect($days)->map(fn (string $day): int => (int) $perDay->get($day, 0))->all();
                })
                ->sortByDesc(fn (array $perDay): int => (int) collect($perDay)->sum())
                ->all(),
            'new_in_range' => $connections->count(),
            'connected_now' => SocialAccount::query()->count(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class BuildChannelGrowth
{
    /**
     * The channels connected per day of the viewer's zone between two days, per network, by
     * the day each was first connected (a reconnect updates the same row).
     * Disconnecting deletes a channel, so a channel removed since is not
     * counted: these are the connections that are still here. The total per
     * day is rebuilt the same way, from the channels still here, so it never
     * shows a day the total went down.
     *
     * @return array{days: list<string>, by_network: array<string, list<int>>, total_by_day: list<int>, new_in_range: int, connected_now: int}
     */
    public static function execute(string $start, string $end, string $timezone): array
    {
        $first = CarbonImmutable::parse($start, $timezone)->startOfDay();
        $last = CarbonImmutable::parse($end, $timezone)->endOfDay();
        $days = collect(range(0, (int) $first->diffInDays($last->startOfDay())))
            ->map(fn (int $offset): string => $first->addDays($offset)->toDateString())
            ->all();

        $connections = SocialAccount::query()
            ->whereBetween('created_at', [$first, $last])
            ->toBase()
            ->get(['platform', 'created_at'])
            ->map(fn (object $channel): array => [
                'network' => Platform::from((string) $channel->platform)->network(),
                'date' => CarbonImmutable::parse($channel->created_at, 'UTC')->setTimezone($timezone)->toDateString(),
            ]);

        $newPerDay = $connections->countBy('date');
        $total = SocialAccount::query()->where('created_at', '<', $first)->count();

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
                ->map(function ($networkConnections) use ($days): array {
                    $perDay = $networkConnections->countBy('date');

                    return collect($days)->map(fn (string $day): int => (int) $perDay->get($day, 0))->all();
                })
                ->sortByDesc(fn (array $perDay): int => array_sum($perDay))
                ->all(),
            'new_in_range' => $connections->count(),
            'connected_now' => SocialAccount::query()->count(),
        ];
    }
}

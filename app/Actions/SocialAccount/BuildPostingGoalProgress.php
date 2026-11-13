<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\User\WeekStart;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;

class BuildPostingGoalProgress
{
    /**
     * Each channel's weekly posting goal and where the viewer's week stands,
     * keyed by channel id, in two queries whatever the number of channels.
     *
     * @param  Collection<int, SocialAccount>  $channels
     * @return array<string, array{posting_goal: ?int, sent_this_week: int, scheduled_this_week: int}>
     */
    public static function forChannels(Collection $channels, WeekStart $weekStart): array
    {
        $sent = CountPostsSentThisWeek::forChannels($channels, $weekStart);
        $scheduled = CountPostsScheduledThisWeek::forChannels($channels, $weekStart);

        return $channels
            ->mapWithKeys(fn (SocialAccount $channel): array => [
                $channel->id => [
                    'posting_goal' => $channel->posting_goal,
                    'sent_this_week' => data_get($sent, $channel->id, 0),
                    'scheduled_this_week' => data_get($scheduled, $channel->id, 0),
                ],
            ])
            ->all();
    }

    /**
     * @return array{posting_goal: ?int, sent_this_week: int, scheduled_this_week: int}
     */
    public static function forChannel(SocialAccount $channel, WeekStart $weekStart): array
    {
        return data_get(self::forChannels(collect([$channel]), $weekStart), $channel->id);
    }
}

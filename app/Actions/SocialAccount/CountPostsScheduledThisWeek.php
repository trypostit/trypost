<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\User\WeekStart;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Timezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CountPostsScheduledThisWeek
{
    /**
     * Posts scheduled on each channel from now to the end of the viewer's
     * current week, keyed by channel id, in one query: the week follows the
     * viewer's `week_starts_on` and each channel's time zone. A channel with
     * nothing scheduled is absent.
     *
     * @param  Collection<int, SocialAccount>  $channels
     * @return array<string, int>
     */
    public static function forChannels(Collection $channels, WeekStart $weekStart): array
    {
        if (blank($channels)) {
            return [];
        }

        return Post::countPerChannel(Post::query()
            ->where(fn (Builder $any): Builder => $channels->reduce(
                fn (Builder $query, SocialAccount $channel): Builder => $query->orWhere(function (Builder $week) use ($channel, $weekStart): void {
                    $now = now(Timezone::normalize($channel->timezone));

                    $week->scheduledOn($channel->id, $now->utc())
                        ->where('scheduled_at', '<=', $now->endOfWeek($weekStart->lastDay())->utc());
                }),
                $any,
            ))
        );
    }
}

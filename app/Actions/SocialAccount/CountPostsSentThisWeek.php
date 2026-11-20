<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\User\WeekStart;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Timezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CountPostsSentThisWeek
{
    /**
     * Posts each channel published in the viewer's current week, keyed by
     * channel id, in one query: the week starts on the viewer's
     * `week_starts_on`, its days follow each channel's time zone. A channel
     * with no post is absent.
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
            ->wentOut()
            ->whereNotNull('published_at')
            ->where(fn (Builder $any): Builder => $channels->reduce(
                fn (Builder $query, SocialAccount $channel): Builder => $query->orWhere(function (Builder $week) use ($channel, $weekStart): void {
                    $now = now(Timezone::normalize($channel->timezone));

                    $week->where('posts.social_account_id', $channel->id)
                        ->whereBetween('published_at', [
                            $now->startOfWeek($weekStart->firstDay())->utc(),
                            $now->endOfWeek($weekStart->lastDay())->utc(),
                        ]);
                }),
                $any,
            ))
        );
    }
}

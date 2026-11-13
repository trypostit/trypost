<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\Post\Status as PostStatus;
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

        return Post::query()
            ->where('status', PostStatus::Scheduled)
            ->where(fn (Builder $any): Builder => $channels->reduce(
                fn (Builder $query, SocialAccount $channel): Builder => $query->orWhere(function (Builder $week) use ($channel, $weekStart): void {
                    $now = now(Timezone::normalize($channel->timezone));

                    $week->where('posts.social_account_id', $channel->id)
                        ->where('scheduled_at', '>', $now->utc())
                        ->where('scheduled_at', '<=', $now->endOfWeek($weekStart->lastDay())->utc());
                }),
                $any,
            ))
            ->toBase()
            ->select('posts.social_account_id')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('posts.social_account_id')
            ->pluck('aggregate', 'social_account_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}

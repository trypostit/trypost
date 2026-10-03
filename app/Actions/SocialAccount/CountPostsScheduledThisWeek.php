<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\User\WeekStart;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Timezone;

class CountPostsScheduledThisWeek
{
    public static function handle(SocialAccount $channel, WeekStart $weekStart): int
    {
        $now = now(Timezone::normalize($channel->timezone));

        return Post::query()
            ->scheduledOn($channel->id, $now->utc())
            ->where('scheduled_at', '<=', $now->endOfWeek($weekStart->lastDay())->utc())
            ->count();
    }
}

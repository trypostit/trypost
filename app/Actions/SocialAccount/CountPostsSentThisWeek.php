<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\User\WeekStart;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Support\Timezone;

class CountPostsSentThisWeek
{
    /**
     * Posts the channel published in the viewer's current week: the week starts
     * on the viewer's `week_starts_on`, its days follow the channel time zone.
     */
    public static function handle(SocialAccount $channel, WeekStart $weekStart): int
    {
        $now = now(Timezone::normalize($channel->timezone));

        return PostPlatform::query()
            ->where('social_account_id', $channel->id)
            ->enabled()
            ->published()
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [
                $now->startOfWeek($weekStart->firstDay())->utc(),
                $now->endOfWeek($weekStart->lastDay())->utc(),
            ])
            ->count();
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Support\Timezone;

/**
 * A channel starts with a usable posting setup: a time zone, a
 * three-a-week goal and the network's recommended slots. New channels get it
 * when they connect.
 */
class ApplyChannelDefaults
{
    public const int GOAL = 3;

    /**
     * Quiet write: no observer, event or PostHog call, and the channel queue
     * is not reflowed.
     */
    public static function execute(SocialAccount $account, ?string $timezone): void
    {
        $account->forceFill([
            'timezone' => Timezone::normalize($timezone),
            'posting_goal' => self::GOAL,
            'posting_schedule' => app(GeneratePostingSchedule::class)->handle($account->platform, self::GOAL),
        ])->saveQuietly();
    }
}

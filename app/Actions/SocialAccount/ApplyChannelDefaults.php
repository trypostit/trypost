<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Support\Timezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * A channel starts with a usable posting setup: a time zone, a
 * three-a-week goal and the network's recommended slots. New channels get it
 * when they connect; channels connected before posting schedules existed get
 * it once through `backfill()` (release:trypost-2).
 */
class ApplyChannelDefaults
{
    public const int GOAL = 3;

    public const int CHUNK = 100;

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

    /**
     * Channels without a posting schedule, which `backfill()` sets up.
     */
    public static function pending(): int
    {
        return self::withoutSchedule()->count();
    }

    /**
     * Gives every channel without a posting schedule the defaults, in its
     * workspace owner's time zone (UTC when the owner has none). A channel
     * that already has a schedule is never touched.
     */
    public static function backfill(): int
    {
        $count = 0;

        self::withoutSchedule()
            ->with('workspace.owner')
            ->chunkById(self::CHUNK, function (Collection $accounts) use (&$count): void {
                foreach ($accounts as $account) {
                    self::execute($account, $account->workspace?->owner?->timezone);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @return Builder<SocialAccount>
     */
    private static function withoutSchedule(): Builder
    {
        return SocialAccount::query()->withoutGlobalScopes()->whereNull('posting_schedule');
    }
}

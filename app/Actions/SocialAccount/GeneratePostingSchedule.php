<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use App\Support\PostingSchedule;
use App\Support\RandomMinute;

class GeneratePostingSchedule
{
    public function __construct(private readonly RandomMinute $randomMinute) {}

    public function handle(Platform $platform, int $goal): PostingSchedule
    {
        $goal = max(1, min(PostingSchedule::MAX_GOAL, $goal));
        $schedule = PostingSchedule::empty();

        foreach (array_slice($platform->recommendedPostingWindows(), 0, $goal) as [$day, $hour]) {
            $schedule = $schedule->withTime($day, sprintf('%02d:%02d', $hour, ($this->randomMinute)()));
        }

        return $schedule;
    }
}

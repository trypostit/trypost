<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Actions\Analytics\RankPostingStreaks as RankPostingStreaksAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:rank-posting-streaks')]
#[Description('Rank every workspace posting streak for the "Top X%" of the streak popover')]
class RankPostingStreaks extends Command
{
    public function handle(RankPostingStreaksAction $ranks): int
    {
        $ranks->execute();

        return self::SUCCESS;
    }
}

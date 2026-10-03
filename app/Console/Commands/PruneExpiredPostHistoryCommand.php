<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Post\PruneExpiredPostHistory;
use App\Support\PostHistoryRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('posts:prune-history {--dry-run : Count the posts without deleting anything}')]
#[Description('Delete published posts, and their media, older than the history retention')]
class PruneExpiredPostHistoryCommand extends Command
{
    public function handle(): int
    {
        try {
            $days = PostHistoryRetention::days();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $count = PruneExpiredPostHistory::execute(now()->subDays($days), $dryRun);

        $this->info($dryRun
            ? "{$count} post(s) would be deleted."
            : "{$count} post(s) deleted.");

        return self::SUCCESS;
    }
}

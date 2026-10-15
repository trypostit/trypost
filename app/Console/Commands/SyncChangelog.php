<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Changelog\SyncChangelogEntries;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncChangelog extends Command
{
    protected $signature = 'changelog:sync';

    protected $description = 'Sync the "What\'s New" entries under resources/changelog/ into the database';

    public function handle(): int
    {
        try {
            $result = SyncChangelogEntries::execute(config('trypost.changelog.path'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Changelog synced: {$result['created']} created, {$result['updated']} updated, {$result['deleted']} deleted.");

        return self::SUCCESS;
    }
}

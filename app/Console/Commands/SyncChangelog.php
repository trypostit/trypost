<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Changelog\SyncChangelogEntries;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncChangelog extends Command
{
    protected $signature = 'changelog:sync {--path= : Folder holding the entries (defaults to changelog/)}';

    protected $description = 'Sync the "What\'s New" entries under changelog/ into the database and upload their covers';

    public function handle(): int
    {
        try {
            $result = SyncChangelogEntries::execute($this->option('path') ?: base_path('changelog'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Changelog synced: {$result['created']} created, {$result['updated']} updated, {$result['deleted']} deleted, {$result['uploaded']} covers uploaded.");

        return self::SUCCESS;
    }
}

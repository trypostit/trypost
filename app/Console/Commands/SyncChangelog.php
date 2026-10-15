<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Changelog\SyncChangelogEntries;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SyncChangelog extends Command
{
    protected $signature = 'changelog:sync
        {--path= : Folder holding the entries (defaults to changelog/)}
        {--covers= : Folder holding the covers (defaults to public/images/changelog/)}';

    protected $description = 'Sync the "What\'s New" entries under changelog/ into the database';

    public function handle(): int
    {
        try {
            $result = SyncChangelogEntries::execute(
                $this->option('path') ?: base_path('changelog'),
                $this->option('covers') ?: public_path('images/changelog'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Changelog synced: {$result['created']} created, {$result['updated']} updated, {$result['deleted']} deleted.");

        return self::SUCCESS;
    }
}

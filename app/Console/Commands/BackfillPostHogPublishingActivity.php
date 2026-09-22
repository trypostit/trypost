<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\PostHog\SyncAccountPublishingActivity;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Console\Command;

class BackfillPostHogPublishingActivity extends Command
{
    protected $signature = 'posthog:backfill-publishing-activity';

    protected $description = 'Queue the latest confirmed publishing activity for every PostHog account group';

    public function handle(): int
    {
        if (! PostHogService::isEnabled()) {
            $this->components->warn('PostHog is disabled; no accounts were queued.');

            return self::SUCCESS;
        }

        $queued = 0;

        Account::query()
            ->select('id')
            ->lazyById()
            ->each(function (Account $account) use (&$queued): void {
                SyncAccountPublishingActivity::dispatch((string) $account->id);
                $queued++;
            });

        $this->components->info("Queued publishing activity sync for {$queued} accounts.");

        return self::SUCCESS;
    }
}

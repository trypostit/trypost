<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WebhookLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:prune-webhook-logs')]
#[Description('Delete webhook logs older than 7 days')]
class PruneWebhookLogs extends Command
{
    public function handle(): int
    {
        $total = 0;

        do {
            $deleted = WebhookLog::query()
                ->where('created_at', '<', now()->subDays(7))
                ->limit(1000)
                ->delete();

            $total += $deleted;
        } while ($deleted > 0);

        $this->info("Pruned {$total} webhook logs.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands\Admin;

use App\Enums\Admin\QuotaSource;
use App\Services\Admin\Quotas\XUsageReader;
use App\Services\Admin\Quotas\YouTubeQuotaReader;
use App\Support\Admin\QuotaSnapshots;
use Illuminate\Console\Command;
use Throwable;

class SyncPlatformQuotas extends Command
{
    protected $signature = 'admin:sync-platform-quotas';

    protected $description = 'Read the YouTube quota and the X spend for the backoffice';

    public function handle(YouTubeQuotaReader $youtube, XUsageReader $x): int
    {
        if ($youtube->isConfigured()) {
            $this->sync(QuotaSource::YouTube, fn (): array => $youtube->read());
        }

        if ($x->isConfigured()) {
            $this->sync(QuotaSource::X, fn (): array => $x->read());
        }

        return self::SUCCESS;
    }

    /**
     * A failed read is reported and writes nothing, so the page keeps showing
     * the last good one; the other sources still run.
     *
     * @param  callable(): array<string, mixed>  $read
     */
    private function sync(QuotaSource $source, callable $read): void
    {
        try {
            QuotaSnapshots::put($source, $read());
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

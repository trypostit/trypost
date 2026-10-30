<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('insights:prune-pdfs')]
#[Description('Delete Insights PDFs older than their retention')]
class PruneInsightsPdfsCommand extends Command
{
    public function handle(): int
    {
        $cutoff = now()->subHours(InsightsPdfExports::RETENTION_HOURS)->getTimestamp();
        $expired = collect(Storage::files(InsightsPdfExports::DIRECTORY))
            ->filter(fn (string $path): bool => Storage::lastModified($path) < $cutoff)
            ->values();

        Storage::delete($expired->all());

        $this->info("{$expired->count()} Insights PDF(s) deleted.");

        return self::SUCCESS;
    }
}

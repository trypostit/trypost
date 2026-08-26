<?php

declare(strict_types=1);

namespace App\Console\Commands\Media;

use App\Services\Media\ChunkedCloudUploader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chunks:prune')]
#[Description('Delete orphaned chunked-upload temp files left behind by abandoned attempts')]
class PruneAbandonedChunks extends Command
{
    public function handle(): int
    {
        $directory = storage_path('app/private/chunks');

        if (! is_dir($directory)) {
            $this->info('No chunk directory to prune.');

            return self::SUCCESS;
        }

        $threshold = now()->subHours(ChunkedCloudUploader::CACHE_TTL_HOURS)->getTimestamp();
        $pruned = 0;

        foreach (glob("{$directory}/*") ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $modifiedAt = @filemtime($file);

            if ($modifiedAt === false || $modifiedAt >= $threshold) {
                continue;
            }

            if (@unlink($file)) {
                $pruned++;
            }
        }

        $this->info("Pruned {$pruned} abandoned chunk file(s).");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Services\Social\FacebookPublisher;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;

class PruneTemporaryUploads
{
    public const int CROP_RETENTION_DAYS = 7;

    /**
     * Deletes unused temporary uploads past the upload retention, and the
     * cropped copies publishers leave in the crop directory. Crop ages come
     * from the directory listing, so the disk is not asked once per file.
     *
     * @return array{uploads: int, crops: int}
     */
    public static function execute(CarbonInterface $now, bool $dryRun = false): array
    {
        return [
            'uploads' => self::pruneUploads($now, $dryRun),
            'crops' => self::pruneCrops($now, $dryRun),
        ];
    }

    private static function pruneUploads(CarbonInterface $now, bool $dryRun): int
    {
        $cutoff = $now->copy()->subHours((int) config('trypost.media.upload_retention_hours'));
        $expired = fn (Builder $query) => $query->temporaryUploads()
            ->whereNull(['post_id', 'idea_id', 'rss_feed_item_id'])
            ->where('created_at', '<', $cutoff);

        if ($dryRun) {
            return Media::query()->tap($expired)->count();
        }

        $pruned = 0;

        Media::query()->tap($expired)->select('id')->chunkById(DeleteOwnedMedia::CHUNK, function (Collection $rows) use (&$pruned, $expired): void {
            $pruned += DB::transaction(fn (): int => DeleteOwnedMedia::forRows($rows->modelKeys(), $expired));
        });

        return $pruned;
    }

    private static function pruneCrops(CarbonInterface $now, bool $dryRun): int
    {
        $cutoff = $now->copy()->subDays(self::CROP_RETENTION_DAYS)->getTimestamp();

        $expired = collect(Storage::listContents(FacebookPublisher::CROP_DIRECTORY, false)->toArray())
            ->filter(fn (StorageAttributes $entry): bool => $entry->isFile() && $entry->lastModified() !== null && $entry->lastModified() < $cutoff)
            ->map(fn (StorageAttributes $entry): string => $entry->path())
            ->values();

        if (! $dryRun) {
            $expired->each(fn (string $path): bool => Storage::delete($path));
        }

        return $expired->count();
    }
}

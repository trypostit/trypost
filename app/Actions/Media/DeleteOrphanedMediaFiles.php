<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class DeleteOrphanedMediaFiles
{
    private const int CHUNK_SIZE = 500;

    /**
     * Delete storage objects for media paths that no longer have DB rows.
     *
     * @param  iterable<int, Media|string|null>  $mediaOrPaths
     */
    public static function execute(iterable $mediaOrPaths): void
    {
        collect($mediaOrPaths)
            ->map(function (Media|string|null $item): ?string {
                if ($item instanceof Media) {
                    return $item->path;
                }

                return $item;
            })
            ->filter()
            ->unique()
            ->values()
            ->chunk(self::CHUNK_SIZE)
            ->each(function (Collection $paths): void {
                $referenced = Media::query()
                    ->whereIn('path', $paths->all())
                    ->distinct()
                    ->pluck('path')
                    ->flip();

                $paths
                    ->reject(fn (string $path): bool => $referenced->has($path))
                    ->each(fn (string $path): bool => Storage::delete($path));
            });
    }
}

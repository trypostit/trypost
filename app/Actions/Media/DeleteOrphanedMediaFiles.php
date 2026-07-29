<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

class DeleteOrphanedMediaFiles
{
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
            ->each(function (string $path): void {
                $stillReferenced = Media::query()
                    ->where('path', $path)
                    ->exists();

                if (! $stillReferenced) {
                    Storage::delete($path);
                }
            });
    }
}

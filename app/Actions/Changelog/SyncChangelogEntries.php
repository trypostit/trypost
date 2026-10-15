<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Dto\ChangelogEntryFile;
use App\Models\ChangelogEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Mirrors the entry folders into `changelog_entries`. Every folder is read
 * and validated (ChangelogEntryFile) before anything is written, a row whose
 * folder is gone is deleted, and any change drops the cached sidebar cards.
 */
class SyncChangelogEntries
{
    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    public static function execute(string $directory): array
    {
        throw_unless(File::isDirectory($directory), InvalidArgumentException::class, "The changelog folder [{$directory}] does not exist.");

        $entries = collect(File::directories($directory))->map(fn (string $folder): ChangelogEntryFile => ChangelogEntryFile::fromFolder($folder));

        $result = DB::transaction(fn (): array => [
            ...self::save($entries),
            'deleted' => ChangelogEntry::query()->whereNotIn('slug', $entries->pluck('slug'))->delete(),
        ]);

        if (array_sum($result) > 0) {
            ShowChangelogCard::forgetAll();
        }

        return $result;
    }

    /**
     * @param  Collection<int, ChangelogEntryFile>  $entries
     * @return array{created: int, updated: int}
     */
    private static function save(Collection $entries): array
    {
        $models = $entries->map(
            fn (ChangelogEntryFile $entry): ChangelogEntry => ChangelogEntry::firstOrNew(['slug' => $entry->slug])->fill($entry->attributes()),
        );

        $counts = [
            'created' => $models->reject->exists->count(),
            'updated' => $models->filter(fn (ChangelogEntry $model): bool => $model->exists && $model->isDirty())->count(),
        ];

        $models->each->save();

        return $counts;
    }
}

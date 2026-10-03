<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Enums\Post\Status as PostStatus;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Moves a workspace from the media library to owned media (spec 7.2): every
 * library row a post or idea references is copied onto that owner, then every
 * library row left is deleted with its file. It finds and copies library rows
 * itself, so it does not depend on what a save accepts.
 *
 * A referenced library row whose file is already gone is deleted while its
 * owner is adopted, so the items pointing at it stay as they are. When a
 * sample of the library is entirely fileless the disk is more likely
 * misconfigured, and nothing happens.
 */
class AdoptWorkspaceLibrary
{
    public const int CHUNK = 200;

    public const int SANITY_SAMPLE = 20;

    public const int FOREIGN_SCAN_CHUNK = 1000;

    /**
     * `foreignReferences()` of the first run of an adoption window (cached for
     * the job's unique lock), so boots inside that window do not scan every
     * post again.
     */
    public const string FOREIGN_REFERENCES_CACHE_KEY = 'media:adopt-library:foreign-references';

    /**
     * Copies a killed run left behind are overwritten, not orphaned: the path
     * only depends on the owner and the library row.
     */
    public const string COPY_PREFIX = 'medias/adopted-';

    /**
     * `skipped`: posts publishing right now. `deferred`: work left for the
     * next run because `$deadline` passed. `kept`: library rows another
     * workspace's post or idea still references. The library is deleted only
     * when `skipped` and `deferred` are zero.
     *
     * `$foreignReferences` is this workspace's slice of `foreignReferences()`;
     * null computes it here, within `$deadline`.
     *
     * @param  array<string, list<string>>|null  $foreignReferences
     * @return array{copied: int, deleted: int, missing: int, skipped: int, deferred: int, kept: int}
     */
    public static function execute(Workspace $workspace, ?CarbonInterface $deadline = null, ?array $foreignReferences = null): array
    {
        $counts = ['copied' => 0, 'deleted' => 0, 'missing' => 0, 'skipped' => 0, 'deferred' => 0, 'kept' => 0];

        if (self::looksMisconfigured($workspace)) {
            Log::error('media:adopt-library skipped a workspace: none of the sampled library files exist on the disk; check the storage configuration', [
                'workspace_id' => $workspace->id,
                'library_rows' => self::library($workspace)->count(),
            ]);

            return $counts;
        }

        $pastDeadline = fn (): bool => $deadline !== null && now()->greaterThan($deadline);

        foreach ([Post::class, Idea::class] as $ownerClass) {
            self::scan($workspace, $ownerClass, function (Post|Idea $owner, array $libraryIds, array $deadIds) use (&$counts, $pastDeadline): void {
                $counts['missing'] += count($deadIds);

                if ($libraryIds === []) {
                    return;
                }

                if ($pastDeadline()) {
                    $counts['deferred']++;

                    return;
                }

                $adopted = self::adopt($owner);

                if ($adopted === null) {
                    $counts['skipped']++;

                    return;
                }

                $counts['copied'] += $adopted['copied'];
                $counts['missing'] += $adopted['missing'];
                $counts['deleted'] += $adopted['missing'];
            });
        }

        if ($counts['skipped'] > 0 || $counts['deferred'] > 0) {
            return $counts;
        }

        if ($pastDeadline()) {
            $counts['deferred']++;

            return $counts;
        }

        if ($foreignReferences === null) {
            $computed = self::foreignReferences([$workspace->id], $deadline);

            if ($computed === null) {
                $counts['deferred']++;

                return $counts;
            }

            $foreignReferences = $computed[$workspace->id] ?? [];
        }

        if ($pastDeadline()) {
            $counts['deferred']++;

            return $counts;
        }

        $result = self::deleteLibrary($workspace, $foreignReferences);

        return [...$counts, 'deleted' => $counts['deleted'] + $result['deleted'], 'kept' => $result['kept']];
    }

    /**
     * What `execute()` would do, without changing anything.
     *
     * @return array{library: int, references: int, orphans: int, missing: int, publishing: int}
     */
    public static function plan(Workspace $workspace): array
    {
        $exists = [];
        $referenced = [];
        $counts = ['library' => self::library($workspace)->count(), 'references' => 0, 'orphans' => 0, 'missing' => 0, 'publishing' => 0];
        $paths = fn (array $ids): array => self::library($workspace)->whereIn('id', $ids)->pluck('path', 'id')->all();

        foreach ([Post::class, Idea::class] as $ownerClass) {
            self::scan($workspace, $ownerClass, function (Post|Idea $owner, array $libraryIds, array $deadIds) use (&$counts, &$referenced, &$exists, $paths): void {
                $counts['missing'] += count($deadIds);

                if ($libraryIds === []) {
                    return;
                }

                if ($owner instanceof Post && $owner->status === PostStatus::Publishing) {
                    $counts['publishing']++;
                }

                $unchecked = array_values(array_diff($libraryIds, array_keys($exists)));

                foreach ($paths($unchecked) as $id => $path) {
                    $exists[$id] = Storage::exists($path);
                }

                foreach ($libraryIds as $libraryId) {
                    $referenced[$libraryId] = true;
                    $counts[($exists[$libraryId] ?? false) ? 'references' : 'missing']++;
                }
            });
        }

        $counts['orphans'] = $counts['library'] - count($referenced);

        return $counts;
    }

    /**
     * No post or idea of the workspace may still point at one of its library
     * rows. Feed items hold no media ids (their image is a row they own).
     */
    public static function assertLibraryUnreferenced(Workspace $workspace): void
    {
        foreach ([Post::class, Idea::class] as $ownerClass) {
            self::scan($workspace, $ownerClass, function (Post|Idea $owner, array $libraryIds) use ($workspace): void {
                if ($libraryIds !== []) {
                    throw new RuntimeException("Library media of workspace {$workspace->id} is still referenced by {$owner->getMorphClass()} {$owner->getKey()}; nothing was deleted.");
                }
            });
        }
    }

    /**
     * Library row ids, per library workspace, that a post or idea of another
     * workspace references, with those owners. One pass over every post and
     * idea, so a run computes it once for all workspaces. Null when
     * `$deadline` passes first.
     *
     * The result stays a superset for as long as the library exists: saves
     * resolve media ids within their own workspace, so a reference to another
     * workspace's row only appears by copying one that already exists
     * (duplicate, draft recovery).
     *
     * @param  list<string>|null  $workspaceIds
     * @return array<string, array<string, list<string>>>|null
     */
    public static function foreignReferences(?array $workspaceIds = null, ?CarbonInterface $deadline = null): ?array
    {
        $found = [];

        foreach ([Post::class, Idea::class] as $ownerClass) {
            $completed = $ownerClass::query()
                ->whereNotNull('media')
                ->select(['id', 'workspace_id', 'media'])
                ->chunkById(self::FOREIGN_SCAN_CHUNK, function (Collection $owners) use (&$found, $workspaceIds, $deadline): bool {
                    if ($deadline !== null && now()->greaterThan($deadline)) {
                        return false;
                    }

                    $itemIds = $owners->flatMap(fn (Post|Idea $owner): array => self::itemIds($owner))->unique()->values()->all();

                    if ($itemIds === []) {
                        return true;
                    }

                    $libraryWorkspaces = Media::query()
                        ->where('collection', Media::LIBRARY_COLLECTION)
                        ->whereIn('id', $itemIds)
                        ->when($workspaceIds !== null, fn (Builder $query) => $query->whereIn('workspace_id', $workspaceIds))
                        ->pluck('workspace_id', 'id');

                    foreach ($owners as $owner) {
                        foreach (self::itemIds($owner) as $id) {
                            $libraryWorkspace = $libraryWorkspaces->get($id);

                            if ($libraryWorkspace !== null && $libraryWorkspace !== $owner->workspace_id) {
                                $found[$libraryWorkspace][$id][] = "{$owner->getMorphClass()}:{$owner->getKey()}";
                            }
                        }
                    }

                    return true;
                });

            if ($completed === false) {
                return null;
            }
        }

        return $found;
    }

    /**
     * One transaction: the library rows are locked first (id order), so a save
     * that resolves one of them waits; the guard then re-reads every owner and
     * only then are the rows deleted. Rows a post or idea of another workspace
     * references are kept and logged.
     *
     * @param  array<string, list<string>>  $foreignReferences
     * @return array{deleted: int, kept: int}
     */
    private static function deleteLibrary(Workspace $workspace, array $foreignReferences): array
    {
        return DB::transaction(function () use ($workspace, $foreignReferences): array {
            $ids = self::library($workspace)->orderBy('id')->lockForUpdate()->pluck('id')->all();

            self::assertLibraryUnreferenced($workspace);

            $foreign = array_intersect_key($foreignReferences, array_flip($ids));

            foreach ($foreign as $libraryId => $owners) {
                Log::warning('media:adopt-library kept a library row another workspace still references', [
                    'workspace_id' => $workspace->id,
                    'media_id' => $libraryId,
                    'owners' => $owners,
                ]);
            }

            $deletable = array_values(array_diff($ids, array_keys($foreign)));

            foreach (array_chunk($deletable, self::CHUNK) as $chunk) {
                DeleteOwnedMedia::forRows($chunk);
            }

            return ['deleted' => count($deletable), 'kept' => count($foreign)];
        });
    }

    /**
     * Calls `$visit` with every owner of the workspace, the distinct library
     * row ids its items reference, and the item ids that match no row at all.
     *
     * @param  class-string<Post|Idea>  $ownerClass
     * @param  Closure(Post|Idea, list<string>, list<string>): void  $visit
     */
    private static function scan(Workspace $workspace, string $ownerClass, Closure $visit): void
    {
        $ownerClass::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('media')
            ->chunkById(self::CHUNK, function (Collection $owners) use ($workspace, $visit): void {
                $itemIds = $owners->flatMap(fn (Post|Idea $owner): array => self::itemIds($owner))->unique()->values()->all();
                $rows = $itemIds === [] ? collect() : Media::query()
                    ->whereIn('id', $itemIds)
                    ->get(['id', 'workspace_id', 'collection'])
                    ->keyBy('id');

                foreach ($owners as $owner) {
                    $ids = self::itemIds($owner);
                    $libraryIds = array_values(array_filter($ids, fn (string $id): bool => $rows->has($id)
                        && $rows->get($id)->collection === Media::LIBRARY_COLLECTION
                        && $rows->get($id)->workspace_id === $workspace->id));
                    $deadIds = array_values(array_filter($ids, fn (string $id): bool => ! $rows->has($id)));

                    $visit($owner, $libraryIds, $deadIds);
                }
            });
    }

    /**
     * Rewrites one owner from its locked, current items: each item that points
     * at a library row gets the owner's own copy (`id`, `path`, `url`); every
     * other field of the item stays. A library row whose file is gone is
     * deleted and its items are left as they are. Null when the post is
     * publishing.
     *
     * @return array{copied: int, missing: int}|null
     */
    private static function adopt(Post|Idea $owner): ?array
    {
        return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($owner): ?array {
            $locked = $owner::query()->whereKey($owner->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return ['copied' => 0, 'missing' => 0];
            }

            if ($locked instanceof Post && $locked->status === PostStatus::Publishing) {
                return null;
            }

            $ids = self::itemIds($locked);
            $library = $ids === [] ? collect() : self::library($locked->workspace)
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $fileless = $library->reject(fn (Media $source): bool => Storage::exists($source->path));
            $items = array_values(array_filter($locked->media ?? [], 'is_array'));
            $copies = [];

            foreach ($items as $index => $item) {
                $source = $library->get((string) data_get($item, 'id'));

                if ($source === null || $fileless->has($source->id)) {
                    continue;
                }

                $copy = $copies[$source->id] ??= self::copy($source, $locked, $batch, $index);
                $items[$index] = [...$item, 'id' => $copy->id, 'path' => $copy->path, 'url' => $copy->url];
            }

            DeleteOwnedMedia::forRows($fileless->modelKeys());

            if ($copies !== []) {
                $locked->forceFill(['media' => $items])->save();
            }

            return ['copied' => count($copies), 'missing' => $fileless->count()];
        });
    }

    private static function copy(Media $source, Post|Idea $owner, MediaCopyBatch $batch, int $position): Media
    {
        $extension = pathinfo($source->path, PATHINFO_EXTENSION);
        $path = self::COPY_PREFIX."{$owner->getKey()}-{$source->id}".($extension !== '' ? ".{$extension}" : '');

        if (! Storage::copy($source->path, $path)) {
            throw new RuntimeException("Could not copy library media {$source->id} to {$path}.");
        }

        $batch->rememberCopy($path);

        return Media::query()->create([
            'workspace_id' => $owner->workspace_id,
            $owner instanceof Post ? 'post_id' : 'idea_id' => $owner->getKey(),
            'group_id' => (string) Str::uuid(),
            'collection' => Media::COLLECTION_MEDIA,
            'type' => $source->type,
            'path' => $path,
            'original_filename' => $source->original_filename,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
            'order' => $position,
            'meta' => [...($source->meta ?? []), 'copied_from' => $source->id],
        ]);
    }

    /**
     * True when every file of a random sample of the library is missing:
     * a wrong disk, bucket or volume, not a library that lost all its files.
     */
    private static function looksMisconfigured(Workspace $workspace): bool
    {
        $sample = self::library($workspace)->inRandomOrder()->limit(self::SANITY_SAMPLE)->pluck('path');

        return $sample->isNotEmpty() && $sample->every(fn (string $path): bool => ! Storage::exists($path));
    }

    /**
     * @return Builder<Media>
     */
    private static function library(Workspace $workspace): Builder
    {
        return Media::query()
            ->where('workspace_id', $workspace->id)
            ->where('collection', Media::LIBRARY_COLLECTION);
    }

    /**
     * @return list<string>
     */
    private static function itemIds(Post|Idea $owner): array
    {
        return collect($owner->media ?? [])
            ->map(fn (mixed $item): mixed => data_get($item, 'id'))
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->unique()
            ->values()
            ->all();
    }
}

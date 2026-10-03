<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Dto\MediaItem;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Support\Media\MediaCopyBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToCopyFile;

/**
 * The only writer of `posts.media` / `ideas.media`: every submitted item ends as
 * a `medias` row owned by the owner (kept, moved from a temporary upload, or
 * copied), the owner's other rows are released, and the JSON is rewritten from
 * the rows plus the per-owner edits, in submitted order.
 */
class SyncOwnedMedia
{
    /**
     * Per-owner edits an item may carry over the row's measured `meta`.
     */
    public const array EDITABLE_META = ['alt_text', 'user_tags', 'cover_offset_ms'];

    /**
     * Row `meta` keys that only describe the row's history and stay off the item.
     */
    private const array ROW_ONLY_META = ['copied_from'];

    /**
     * Item fields that describe where the composer found the file, not the file.
     */
    private const array ITEM_FIELDS = ['source', 'source_meta'];

    /**
     * Call inside the transaction of `MediaCopyBatch::run()`. Locks the owner,
     * then every media row it touches (its own and the sources) in id order,
     * the same order the delete paths take.
     *
     * `$errorKey` prefixes item errors (`media`, or `destinations.{n}.media`
     * for a channel's own list). `$legacyItems` are stored items of another
     * owner (duplicate, recovery) that may pass through without a row, like
     * the owner's own stored items do (spec 7.2: missing files are left as is).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $legacyItems
     * @return list<array<string, mixed>>
     */
    public static function execute(Post|Idea $owner, array $items, MediaCopyBatch $batch, string $errorKey = 'media', array $legacyItems = []): array
    {
        $ownerColumn = $owner instanceof Post ? 'post_id' : 'idea_id';
        $locked = $owner::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

        $workspace = $owner->workspace;
        $items = self::withoutRepeats(array_values($items));
        $ids = self::column($items, 'id');
        $uploadTokens = self::column($items, 'upload_token');
        self::lockRows($workspace->id, $ownerColumn, $owner->getKey(), $ids, $uploadTokens);

        $rows = ResolveWorkspaceMedia::execute($workspace, $ids, lockForUpdate: true);
        $tokens = ResolveWorkspaceMedia::byUploadTokens($workspace, $uploadTokens, lockForUpdate: true);
        $passThrough = collect([...$legacyItems, ...($locked->media ?? [])])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->keyBy(fn (array $item): string => (string) data_get($item, 'id'));

        $final = [];
        foreach ($items as $index => $item) {
            $token = data_get($item, 'upload_token');
            $source = filled($token)
                ? ($tokens->get($token) ?? self::spentTokenSource($rows->get(data_get($item, 'id')), $ownerColumn, $owner, $batch))
                : $rows->get(data_get($item, 'id'));
            $key = "{$errorKey}.{$index}";

            if ($source === null && ! filled($token) && $passThrough->has((string) data_get($item, 'id'))) {
                $final[] = self::withEdits($passThrough->get((string) data_get($item, 'id')), $item);

                continue;
            }

            if ($source === null) {
                throw ValidationException::withMessages([$key => filled($token)
                    ? __('posts.errors.media_expired')
                    : __('validation.exists', ['attribute' => 'media'])]);
            }

            $position = count($final);
            $owned = match (true) {
                $source->{$ownerColumn} === $owner->getKey() => self::ordered($source, $position),
                $batch->canMove($source) => self::adopt($source, $ownerColumn, $owner, $batch, $position),
                default => self::copy($source, $ownerColumn, $owner, $batch, $position, $key),
            };

            $final[] = self::withEdits(self::item($owned), $item);
        }

        DeleteOwnedMedia::forRows($owner->ownedMedia()->whereNotIn('id', self::column($final, 'id'))->pluck('id')->all());
        $owner->forceFill(['media' => $final])->save();

        return $final;
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>  $uploadTokens
     */
    private static function lockRows(string $workspaceId, string $ownerColumn, string $ownerId, array $ids, array $uploadTokens): void
    {
        Media::query()
            ->where('workspace_id', $workspaceId)
            ->where(fn (Builder $query) => $query
                ->where($ownerColumn, $ownerId)
                ->when($ids !== [], fn (Builder $query) => $query->orWhereIn('id', $ids))
                ->when($uploadTokens !== [], fn (Builder $query) => $query->orWhereIn('upload_token', $uploadTokens)))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    /**
     * A token that no longer finds an upload still stands for its row when this
     * owner already holds it, or this very save moved it to an earlier owner
     * (one upload shared by several posts). Anyone else holding it means the
     * upload was consumed by another save.
     */
    private static function spentTokenSource(?Media $row, string $ownerColumn, Post|Idea $owner, MediaCopyBatch $batch): ?Media
    {
        return $row !== null && ($row->{$ownerColumn} === $owner->getKey() || $batch->wasMoved($row->id)) ? $row : null;
    }

    private static function ordered(Media $owned, int $position): Media
    {
        if ($owned->order !== $position) {
            $owned->update(['order' => $position]);
        }

        return $owned;
    }

    private static function adopt(Media $source, string $ownerColumn, Post|Idea $owner, MediaCopyBatch $batch, int $position): Media
    {
        $source->update([
            $ownerColumn => $owner->getKey(),
            'mediable_type' => null,
            'mediable_id' => null,
            'collection' => Media::COLLECTION_MEDIA,
            'upload_token' => null,
            'order' => $position,
        ]);
        $batch->rememberMove($source->id);

        return $source;
    }

    /**
     * A source whose file can no longer be copied fails its item like an
     * expired upload; no row is created for it.
     */
    private static function copy(Media $source, string $ownerColumn, Post|Idea $owner, MediaCopyBatch $batch, int $position, string $errorKey): Media
    {
        $extension = pathinfo($source->path, PATHINFO_EXTENSION);
        $path = 'medias/'.Str::uuid().($extension !== '' ? ".{$extension}" : '');

        try {
            $copied = Storage::copy($source->path, $path);
        } catch (UnableToCopyFile) {
            $copied = false;
        }

        if (! $copied) {
            throw ValidationException::withMessages([$errorKey => __('posts.errors.media_expired')]);
        }

        $batch->rememberCopy($path);

        return Media::query()->create([
            'workspace_id' => $owner->workspace_id,
            $ownerColumn => $owner->getKey(),
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
     * Keeps the first occurrence of an id or upload token, with its index.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private static function withoutRepeats(array $items): array
    {
        $seen = [];

        return array_filter($items, function (array $item) use (&$seen): bool {
            $identity = filled(data_get($item, 'upload_token'))
                ? 'token:'.data_get($item, 'upload_token')
                : 'id:'.data_get($item, 'id');

            if (isset($seen[$identity])) {
                return false;
            }

            $seen[$identity] = true;

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function item(Media $owned): array
    {
        $item = MediaItem::fromMedia($owned)->toArray();
        $meta = array_diff_key((array) data_get($item, 'meta', []), array_flip(self::ROW_ONLY_META));
        unset($item['meta']);

        return $meta === [] ? $item : [...$item, 'meta' => $meta];
    }

    /**
     * @param  array<string, mixed>  $owned
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private static function withEdits(array $owned, array $submitted): array
    {
        $edits = array_intersect_key((array) data_get($submitted, 'meta', []), array_flip(self::EDITABLE_META));

        if (is_numeric(data_get($edits, 'cover_offset_ms'))) {
            $edits['cover_offset_ms'] = (int) $edits['cover_offset_ms'];
        }

        if ($edits !== []) {
            $owned['meta'] = [...((array) data_get($owned, 'meta', [])), ...$edits];
        }

        foreach (self::ITEM_FIELDS as $field) {
            if (array_key_exists($field, $submitted)) {
                $owned[$field] = $submitted[$field];
            }
        }

        return $owned;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private static function column(array $items, string $key): array
    {
        return array_values(array_filter(
            array_map(fn (array $item): mixed => data_get($item, $key), $items),
            fn (mixed $value): bool => is_string($value) && Str::isUuid($value),
        ));
    }
}

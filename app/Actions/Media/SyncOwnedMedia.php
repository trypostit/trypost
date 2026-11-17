<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Dto\MediaItem;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Support\Media\MediaCopyBatch;
use App\Support\ThreadReplies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
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
    public const array EDITABLE_META = ['alt_text', 'user_tags', 'cover_offset_ms', 'cover_source'];

    /**
     * Everything an item carries for its owner over the row's `meta`: the
     * edits and the image picked as a video's cover (`cover`, a row of its own).
     */
    public const array OWNER_META = [...self::EDITABLE_META, 'cover'];

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
     * A post's thread replies (`meta.thread_replies`) own media too: their
     * items are synced the same way and written back to the reply, and their
     * rows are kept, so save the post's meta before calling this. Their errors
     * are keyed under `$metaErrorKey` (`meta`, or `destinations.{n}.meta` in a
     * batch), whichever list the root media came from.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $legacyItems
     * @return list<array<string, mixed>>
     */
    public static function execute(Post|Idea $owner, array $items, MediaCopyBatch $batch, string $errorKey = 'media', array $legacyItems = [], string $metaErrorKey = 'meta'): array
    {
        $ownerColumn = $owner instanceof Post ? 'post_id' : 'idea_id';
        $locked = $owner::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

        $workspace = $owner->workspace;
        $items = self::withoutRepeats(array_values($items));
        $replies = $locked instanceof Post ? ThreadReplies::of($locked->meta) : [];
        $submitted = [...$items, ...MediaItem::covers($items), ...self::replyMedia($replies)];
        $ids = self::column($submitted, 'id');
        $uploadTokens = self::column($submitted, 'upload_token');
        self::lockRows($workspace->id, $ownerColumn, $owner->getKey(), $ids, $uploadTokens);

        $rows = ResolveWorkspaceMedia::execute($workspace, $ids, lockForUpdate: true);
        $tokens = ResolveWorkspaceMedia::byUploadTokens($workspace, $uploadTokens, lockForUpdate: true);
        $stored = [...$legacyItems, ...($locked->media ?? [])];
        $passThrough = collect([...$stored, ...MediaItem::covers($stored)])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->keyBy(fn (array $item): string => (string) data_get($item, 'id'));

        $position = 0;
        $own = function (array $item, string $key, int $position) use ($owner, $ownerColumn, $batch, $rows, $tokens, $passThrough): array {
            $token = data_get($item, 'upload_token');
            $source = filled($token)
                ? ($tokens->get($token) ?? self::spentTokenSource($rows->get(data_get($item, 'id')), $ownerColumn, $owner, $batch))
                : $rows->get(data_get($item, 'id'));

            if (blank($source) && blank($token) && $passThrough->has((string) data_get($item, 'id'))) {
                return $passThrough->get((string) data_get($item, 'id'));
            }

            if (blank($source)) {
                throw ValidationException::withMessages([$key => filled($token)
                    ? __('posts.errors.media_expired')
                    : __('validation.exists', ['attribute' => 'media'])]);
            }

            $owned = match (true) {
                $source->{$ownerColumn} === $owner->getKey() => self::ordered($source, $position),
                $batch->canMove($source) => self::adopt($source, $ownerColumn, $owner, $batch, $position),
                default => self::copy($source, $ownerColumn, $owner, $batch, $position, $key),
            };

            return self::item($owned);
        };

        $keepsCovers = self::keepsCovers($locked);
        $sync = function (array $items, string $errorKey) use ($own, $keepsCovers, &$position): array {
            $final = [];

            foreach ($items as $index => $item) {
                $key = "{$errorKey}.{$index}";
                $owned = self::withEdits(Arr::except($own($item, $key, $position), 'meta.cover'), $item);
                $cover = data_get($item, 'meta.cover');

                if ($keepsCovers && is_array($cover)) {
                    $owned['meta'] = [...(array) data_get($owned, 'meta', []), 'cover' => self::coverItem($own($cover, "{$key}.meta.cover", $position), "{$key}.meta.cover")];
                }

                if (blank(data_get($owned, 'meta.cover'))) {
                    Arr::forget($owned, 'meta.cover_source');
                }

                $position++;
                $final[] = $owned;
            }

            return $final;
        };

        $final = $sync($items, $errorKey);
        $replies = collect($replies)
            ->map(fn (array $reply, int $index): array => [
                ...$reply,
                'media' => $sync(self::withoutRepeats(self::withoutCovers($reply['media'])), "{$metaErrorKey}.thread_replies.{$index}.media"),
            ])
            ->all();
        $kept = self::column([...$final, ...MediaItem::covers($final), ...self::replyMedia($replies)], 'id');

        DeleteOwnedMedia::forRows($owner->ownedMedia()->whereNotIn('id', $kept)->pluck('id')->all());
        $owner->update($replies === [] ? ['media' => $final] : [
            'media' => $final,
            'meta' => [...($locked->meta ?? []), 'thread_replies' => $replies],
        ]);

        return $final;
    }

    /**
     * The cover as stored under its video: a still image, the file only,
     * without per-owner edits or the composer's source fields.
     *
     * @param  array<string, mixed>  $owned
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function coverItem(array $owned, string $key): array
    {
        if (! MediaItem::fromArray($owned)->isStillImage()) {
            throw ValidationException::withMessages([$key => __('posts.errors.cover_not_image')]);
        }

        $cover = Arr::except($owned, [...self::ITEM_FIELDS, 'upload_token', 'meta']);
        $meta = Arr::except((array) data_get($owned, 'meta', []), self::OWNER_META);

        if (blank($meta)) {
            return $cover;
        }

        return [...$cover, 'meta' => $meta];
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
     * Only a post whose network takes a cover image keeps one: an idea keeps
     * it for the post it becomes, and a post on a network that only takes a
     * frame (TikTok, a Pinterest pin, an Instagram feed video) drops it, as
     * `PostMeta::forChannel()` drops a poll on any other type.
     */
    private static function keepsCovers(Post|Idea $owner): bool
    {
        if ($owner instanceof Idea) {
            return true;
        }

        return (bool) $owner->content_type?->supportsCustomVideoCover();
    }

    /**
     * A thread reply's video takes no cover image: only a post's own video
     * publishes with one.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function withoutCovers(array $items): array
    {
        return collect($items)
            ->map(fn (array $item): array => Arr::except($item, 'meta.cover'))
            ->all();
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
     * @param  list<array{media: list<array<string, mixed>>}>  $replies
     * @return list<array<string, mixed>>
     */
    private static function replyMedia(array $replies): array
    {
        return array_merge([], ...array_column($replies, 'media'));
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

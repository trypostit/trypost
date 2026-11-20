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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
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
     * The order the next item takes among the owner's rows, across its own
     * media and its thread replies'.
     */
    private int $position = 0;

    /**
     * @param  Collection<string, Media>  $rows  the submitted ids' rows
     * @param  Collection<string, Media>  $uploads  the submitted upload tokens' rows
     * @param  Collection<string, array<string, mixed>>  $passThrough  stored items that may stay without a row, by id
     */
    private function __construct(
        private readonly Post|Idea $owner,
        private readonly string $ownerColumn,
        private readonly bool $keepsCovers,
        private readonly MediaCopyBatch $batch,
        private readonly Collection $rows,
        private readonly Collection $uploads,
        private readonly Collection $passThrough,
    ) {}

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
        $ownerColumn = self::ownerColumn($owner);
        $locked = $owner::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();

        $workspace = $owner->workspace;
        $items = self::withoutRepeats(array_values($items));
        $replies = self::repliesOf($locked);
        $submitted = [...$items, ...MediaItem::covers($items), ...self::replyMedia($replies)];
        $ids = self::uuids($submitted, 'id');
        $uploadTokens = self::uuids($submitted, 'upload_token');
        self::lockRows($workspace->id, $ownerColumn, $owner->getKey(), $ids, $uploadTokens);

        $stored = [...$legacyItems, ...($locked->media ?? [])];
        $sync = new self(
            owner: $owner,
            ownerColumn: $ownerColumn,
            keepsCovers: self::keepsCovers($locked),
            batch: $batch,
            rows: ResolveWorkspaceMedia::execute($workspace, $ids, lockForUpdate: true),
            uploads: ResolveWorkspaceMedia::byUploadTokens($workspace, $uploadTokens, lockForUpdate: true),
            passThrough: collect([...$stored, ...MediaItem::covers($stored)])
                ->filter(fn (mixed $item): bool => is_array($item))
                ->keyBy(fn (array $item): string => (string) data_get($item, 'id')),
        );

        $final = $sync->items($items, $errorKey);
        $replies = collect($replies)
            ->map(fn (array $reply, int $index): array => [
                ...$reply,
                'media' => $sync->items(self::withoutRepeats(self::withoutCovers($reply['media'])), "{$metaErrorKey}.thread_replies.{$index}.media"),
            ])
            ->all();
        $kept = self::uuids([...$final, ...MediaItem::covers($final), ...self::replyMedia($replies)], 'id');

        DeleteOwnedMedia::forRows($owner->ownedMedia()->whereNotIn('id', $kept)->pluck('id')->all());
        $owner->update(self::attributes($locked, $final, $replies));

        return $final;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function items(array $items, string $errorKey): array
    {
        $final = [];

        foreach ($items as $index => $item) {
            $final[] = $this->item($item, "{$errorKey}.{$index}");
            $this->position++;
        }

        return $final;
    }

    /**
     * One submitted item as stored: its row, the per-owner edits, and the
     * cover image when the owner keeps one. A cover source means nothing
     * without its cover, so it goes when the cover does.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function item(array $item, string $key): array
    {
        $owned = self::withEdits(Arr::except($this->own($item, $key), 'meta.cover'), $item);
        $cover = $this->cover($item, "{$key}.meta.cover");

        if (blank($cover)) {
            Arr::forget($owned, 'meta.cover_source');

            return $owned;
        }

        data_set($owned, 'meta.cover', $cover);

        return $owned;
    }

    /**
     * The cover image of a submitted video, owned like the video and at its
     * position.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     *
     * @throws ValidationException
     */
    private function cover(array $item, string $key): ?array
    {
        $cover = data_get($item, 'meta.cover');

        if (! $this->keepsCovers || ! is_array($cover)) {
            return null;
        }

        return self::coverItem($this->own($cover, $key), $key);
    }

    /**
     * The submitted item as a row of the owner (kept, adopted or copied), or
     * the stored item itself when it has no row left and may stay as it is.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function own(array $item, string $key): array
    {
        $source = $this->source($item);

        if (filled($source)) {
            return self::itemOf($this->ownedRow($source, $key));
        }

        $id = (string) data_get($item, 'id');

        if (blank(data_get($item, 'upload_token')) && $this->passThrough->has($id)) {
            return $this->passThrough->get($id);
        }

        throw ValidationException::withMessages([$key => self::missingSourceMessage($item)]);
    }

    /**
     * The row a submitted item stands for: its upload when it carries a token,
     * else the row of its id.
     *
     * @param  array<string, mixed>  $item
     */
    private function source(array $item): ?Media
    {
        $row = $this->rows->get(data_get($item, 'id'));
        $token = data_get($item, 'upload_token');

        if (blank($token)) {
            return $row;
        }

        return $this->uploads->get($token) ?? $this->spentTokenSource($row);
    }

    /**
     * A token that no longer finds an upload still stands for its row when this
     * owner already holds it, or this very save moved it to an earlier owner
     * (one upload shared by several posts). Anyone else holding it means the
     * upload was consumed by another save.
     */
    private function spentTokenSource(?Media $row): ?Media
    {
        if (blank($row)) {
            return null;
        }

        if ($this->ownsRow($row)) {
            return $row;
        }

        if ($this->batch->wasMoved($row->id)) {
            return $row;
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    private function ownedRow(Media $source, string $key): Media
    {
        if ($this->ownsRow($source)) {
            return $this->ordered($source);
        }

        if ($this->batch->canMove($source)) {
            return $this->adopt($source);
        }

        return $this->copy($source, $key);
    }

    private function ownsRow(Media $row): bool
    {
        return $row->{$this->ownerColumn} === $this->owner->getKey();
    }

    private function ordered(Media $owned): Media
    {
        if ($owned->order !== $this->position) {
            $owned->update(['order' => $this->position]);
        }

        return $owned;
    }

    private function adopt(Media $source): Media
    {
        $source->update([
            $this->ownerColumn => $this->owner->getKey(),
            'mediable_type' => null,
            'mediable_id' => null,
            'collection' => Media::COLLECTION_MEDIA,
            'upload_token' => null,
            'order' => $this->position,
        ]);
        $this->batch->rememberMove($source->id);

        return $source;
    }

    /**
     * A source whose file can no longer be copied fails its item like an
     * expired upload; no row is created for it.
     *
     * @throws ValidationException
     */
    private function copy(Media $source, string $key): Media
    {
        $path = self::copyPath($source->path);

        if (! self::copyFile($source->path, $path)) {
            throw ValidationException::withMessages([$key => __('posts.errors.media_expired')]);
        }

        $this->batch->rememberCopy($path);

        return Media::query()->create([
            'workspace_id' => $this->owner->workspace_id,
            $this->ownerColumn => $this->owner->getKey(),
            'group_id' => (string) Str::uuid(),
            'collection' => Media::COLLECTION_MEDIA,
            'type' => $source->type,
            'path' => $path,
            'original_filename' => $source->original_filename,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
            'order' => $this->position,
            'meta' => [...($source->meta ?? []), 'copied_from' => $source->id],
        ]);
    }

    private static function copyPath(string $sourcePath): string
    {
        $path = 'medias/'.Str::uuid();
        $extension = File::extension($sourcePath);

        if (blank($extension)) {
            return $path;
        }

        return "{$path}.{$extension}";
    }

    private static function copyFile(string $from, string $to): bool
    {
        try {
            return Storage::copy($from, $to);
        } catch (UnableToCopyFile) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function missingSourceMessage(array $item): string
    {
        if (filled(data_get($item, 'upload_token'))) {
            return __('posts.errors.media_expired');
        }

        return __('validation.exists', ['attribute' => 'media']);
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

        return self::withMeta(
            Arr::except($owned, [...self::ITEM_FIELDS, 'upload_token', 'meta']),
            Arr::except((array) data_get($owned, 'meta', []), self::OWNER_META),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function itemOf(Media $owned): array
    {
        $item = MediaItem::fromMedia($owned)->toArray();

        return self::withMeta(
            Arr::except($item, 'meta'),
            Arr::except((array) data_get($item, 'meta', []), self::ROW_ONLY_META),
        );
    }

    /**
     * An item with its `meta`, left off when there is none.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function withMeta(array $item, array $meta): array
    {
        if (blank($meta)) {
            return $item;
        }

        return [...$item, 'meta' => $meta];
    }

    /**
     * @param  array<string, mixed>  $owned
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    private static function withEdits(array $owned, array $submitted): array
    {
        $edits = Arr::only((array) data_get($submitted, 'meta', []), self::EDITABLE_META);

        if (is_numeric(data_get($edits, 'cover_offset_ms'))) {
            $edits['cover_offset_ms'] = (int) $edits['cover_offset_ms'];
        }

        if (filled($edits)) {
            $owned['meta'] = [...(array) data_get($owned, 'meta', []), ...$edits];
        }

        return [...$owned, ...Arr::only($submitted, self::ITEM_FIELDS)];
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
                ->when(filled($ids), fn (Builder $query) => $query->orWhereIn('id', $ids))
                ->when(filled($uploadTokens), fn (Builder $query) => $query->orWhereIn('upload_token', $uploadTokens)))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    private static function ownerColumn(Post|Idea $owner): string
    {
        if ($owner instanceof Post) {
            return 'post_id';
        }

        return 'idea_id';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function repliesOf(Post|Idea $owner): array
    {
        if ($owner instanceof Post) {
            return ThreadReplies::of($owner->meta);
        }

        return [];
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
     * What the owner stores: its media, and its thread replies' media written
     * back into its meta when it has replies.
     *
     * @param  list<array<string, mixed>>  $media
     * @param  list<array<string, mixed>>  $replies
     * @return array<string, mixed>
     */
    private static function attributes(Post|Idea $locked, array $media, array $replies): array
    {
        if (blank($replies)) {
            return ['media' => $media];
        }

        return [
            'media' => $media,
            'meta' => [...($locked->meta ?? []), 'thread_replies' => $replies],
        ];
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
        return collect($items)
            ->unique(fn (array $item): string => self::identity($item))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function identity(array $item): string
    {
        $token = data_get($item, 'upload_token');

        if (filled($token)) {
            return "token:{$token}";
        }

        return 'id:'.data_get($item, 'id');
    }

    /**
     * @param  list<array{media: list<array<string, mixed>>}>  $replies
     * @return list<array<string, mixed>>
     */
    private static function replyMedia(array $replies): array
    {
        return collect($replies)->pluck('media')->flatten(1)->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<string>
     */
    private static function uuids(array $items, string $key): array
    {
        return collect($items)
            ->map(fn (array $item): mixed => data_get($item, $key))
            ->filter(fn (mixed $value): bool => is_string($value) && Str::isUuid($value))
            ->values()
            ->all();
    }
}

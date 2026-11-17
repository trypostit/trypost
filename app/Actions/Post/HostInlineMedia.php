<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\DeleteOwnedMedia;
use App\Actions\Media\ResolveWorkspaceMedia;
use App\Actions\Media\SyncOwnedMedia;
use App\Dto\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\Post\MediaAttacher;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the API/MCP media references of a post into whole hosted items.
 * An `upload_token` finds a temporary upload (the item keeps the token, so
 * the save consumes it under a row lock and a second use fails), an `id` a
 * media row of the workspace and a `url` is downloaded as a new temporary
 * upload, once per distinct URL of the request. The save that follows moves
 * the row to its first owner and copies it for the next ones.
 *
 * An item that cannot be resolved rejects the request (422) and deletes every
 * download of the request so far. Downloads left behind by a later failure of
 * the save stay temporary uploads that `media:prune-uploads` removes.
 */
class HostInlineMedia
{
    /**
     * The row each URL of this request was downloaded to, so a URL used by
     * several items or destinations is downloaded once.
     *
     * @var array<string, string>
     */
    private array $downloads = [];

    /**
     * The uploads and rows the list being hosted refers to.
     *
     * @var Collection<string, Media>
     */
    private Collection $byToken;

    /**
     * @var Collection<string, Media>
     */
    private Collection $byId;

    private function __construct(private readonly Workspace $workspace) {}

    /**
     * Host the shared media and every destination's own media of a batch
     * composition in one go, so a URL used by several destinations is
     * downloaded once.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forBatch(Workspace $workspace, array $data): array
    {
        $host = new self($workspace);

        if (Arr::exists($data, 'media')) {
            $data['media'] = $host->media(MediaType::cases(), $data['media'], 'media');
        }

        foreach (data_get($data, 'destinations', []) as $index => $destination) {
            if (Arr::exists($destination, 'media')) {
                $data['destinations'][$index]['media'] = $host->media(MediaType::cases(), $destination['media'], "destinations.{$index}.media");
            }
        }

        foreach (data_get($data, 'destinations', []) as $index => $destination) {
            if (is_array(data_get($destination, 'meta'))) {
                $data['destinations'][$index]['meta'] = $host->threadReplies($destination['meta'], "destinations.{$index}.meta");
            }
        }

        return $data;
    }

    /**
     * Host the media of a single post request and of its thread replies
     * (`meta.thread_replies.*.media`), so a URL is accepted wherever a post
     * takes media.
     *
     * @param  array<MediaType>  $allowedTypes
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function forPost(Workspace $workspace, array $allowedTypes, array $data): array
    {
        $host = new self($workspace);

        if (Arr::exists($data, 'media')) {
            $data['media'] = $host->media($allowedTypes, $data['media'], 'media');
        }

        if (is_array(data_get($data, 'meta'))) {
            $data['meta'] = $host->threadReplies($data['meta'], 'meta');
        }

        return $data;
    }

    /**
     * The row as the composer shape the save validates, with the client's
     * editable meta (alt text, user tags, cover offset and source) on top of
     * what is stored. The `alt` shorthand applies to images and videos (a PDF
     * has no alt text), and never over an `alt_text` sent in `meta`.
     *
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>
     */
    public static function item(Media $row, array $reference): array
    {
        $item = MediaItem::fromMedia($row)->toArray();
        $edits = Arr::only((array) data_get($reference, 'meta', []), SyncOwnedMedia::EDITABLE_META);
        $item['meta'] = [...(array) data_get($item, 'meta', []), ...self::withAltShorthand($row, $edits, $reference)];

        return $item;
    }

    /**
     * One list of references as hosted items, in index order: `validated()`
     * can return the items out of order when their shapes differ (it fills one
     * rule at a time).
     *
     * @param  array<MediaType>  $allowedTypes
     * @param  array<int, array<string, mixed>>  $media
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    private function media(array $allowedTypes, array $media, string $errorKey): array
    {
        if (blank($media)) {
            return $media;
        }

        $media = collect($media)->sortKeys()->values()->all();
        $this->resolveReferences([...$media, ...MediaItem::covers($media)]);

        try {
            return collect($media)
                ->map(fn (array $item, int $index): array => $this->withCover($this->host($allowedTypes, $item, "{$errorKey}.{$index}"), $item, "{$errorKey}.{$index}.meta.cover"))
                ->all();
        } catch (ValidationException $exception) {
            $this->forgetDownloads();

            throw $exception;
        }
    }

    /**
     * A video's cover (`meta.cover`), hosted like the video: always an image.
     *
     * @param  array<string, mixed>  $hosted
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function withCover(array $hosted, array $item, string $key): array
    {
        $cover = data_get($item, 'meta.cover');

        if (! is_array($cover)) {
            return $hosted;
        }

        data_set($hosted, 'meta.cover', $this->host([MediaType::Image], $cover, $key));

        return $hosted;
    }

    /**
     * One reference (`upload_token`, `id` or `url`) as a hosted item.
     *
     * @param  array<MediaType>  $allowedTypes
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function host(array $allowedTypes, array $item, string $key): array
    {
        $token = data_get($item, 'upload_token');

        if (filled($token)) {
            return [...self::item($this->upload($token, $key), $item), 'upload_token' => $token];
        }

        $id = data_get($item, 'id');

        if (filled($id)) {
            return self::item($this->row($id, $key), $item);
        }

        return self::item($this->download($allowedTypes, (string) data_get($item, 'url'), $key), $item);
    }

    /**
     * @throws ValidationException
     */
    private function upload(string $token, string $key): Media
    {
        $upload = $this->byToken->get($token);

        if (blank($upload)) {
            throw ValidationException::withMessages(["{$key}.upload_token" => __('posts.errors.media_expired')]);
        }

        return $upload;
    }

    /**
     * @throws ValidationException
     */
    private function row(string $id, string $key): Media
    {
        $row = $this->byId->get($id);

        if (blank($row)) {
            throw ValidationException::withMessages(["{$key}.id" => __('validation.exists', ['attribute' => 'media'])]);
        }

        return $row;
    }

    /**
     * The URL as a temporary upload, downloaded the first time this request
     * sees it.
     *
     * @param  array<MediaType>  $allowedTypes
     *
     * @throws ValidationException
     */
    private function download(array $allowedTypes, string $url, string $key): Media
    {
        if (! Arr::exists($this->downloads, $url)) {
            $this->downloads[$url] = $this->import($allowedTypes, $url, $key);
        }

        return Media::query()->findOrFail($this->downloads[$url]);
    }

    /**
     * Downloads the URL as a temporary upload and returns its row id.
     *
     * @param  array<MediaType>  $allowedTypes
     *
     * @throws ValidationException
     */
    private function import(array $allowedTypes, string $url, string $key): string
    {
        $imported = app(MediaAttacher::class)->importUpload($this->workspace, $allowedTypes, $url);

        if (blank($imported->media)) {
            throw ValidationException::withMessages(["{$key}.url" => MediaAttacher::failureMessage($imported, $url)]);
        }

        return $imported->media->id;
    }

    /**
     * Deletes every download of the request, once a reference fails it.
     */
    private function forgetDownloads(): void
    {
        if (blank($this->downloads)) {
            return;
        }

        DB::transaction(fn () => DeleteOwnedMedia::forRows(array_values($this->downloads)));
        $this->downloads = [];
    }

    /**
     * Loads, in two queries, the uploads and rows a list of references names.
     *
     * @param  array<int, array<string, mixed>>  $references
     */
    private function resolveReferences(array $references): void
    {
        $ids = collect(self::column($references, 'id'))
            ->filter(fn (string $id): bool => Str::isUuid($id))
            ->values()
            ->all();

        $this->byToken = ResolveWorkspaceMedia::byUploadTokens($this->workspace, self::column($references, 'upload_token'));
        $this->byId = ResolveWorkspaceMedia::execute($this->workspace, $ids);
    }

    /**
     * Hosts the media of each thread reply that refers to a URL.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function threadReplies(array $meta, string $errorPrefix): array
    {
        foreach ((array) data_get($meta, 'thread_replies', []) as $index => $reply) {
            $media = (array) data_get($reply, 'media', []);

            if (self::hasUrl($media)) {
                $meta['thread_replies'][$index]['media'] = $this->media(MediaType::cases(), $media, "{$errorPrefix}.thread_replies.{$index}.media");
            }
        }

        return $meta;
    }

    /**
     * @param  array<int, mixed>  $media
     */
    private static function hasUrl(array $media): bool
    {
        return collect($media)->contains(fn (mixed $item): bool => filled(data_get($item, 'url')));
    }

    /**
     * @param  array<string, mixed>  $edits
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>
     */
    private static function withAltShorthand(Media $row, array $edits, array $reference): array
    {
        $alt = data_get($reference, 'alt');

        if ($row->type === MediaType::Document) {
            return $edits;
        }

        if (blank($alt)) {
            return $edits;
        }

        if (Arr::exists($edits, 'alt_text')) {
            return $edits;
        }

        return [...$edits, 'alt_text' => $alt];
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @return list<string>
     */
    private static function column(array $media, string $key): array
    {
        return collect($media)
            ->pluck($key)
            ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
            ->unique()
            ->values()
            ->all();
    }
}

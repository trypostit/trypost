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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HostInlineMedia
{
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
        $fetched = [];

        if (array_key_exists('media', $data)) {
            $data['media'] = self::execute($workspace, MediaType::cases(), $data['media'], fetched: $fetched);
        }

        foreach (data_get($data, 'destinations', []) as $index => $destination) {
            if (array_key_exists('media', $destination)) {
                $data['destinations'][$index]['media'] = self::execute(
                    $workspace,
                    MediaType::cases(),
                    $destination['media'],
                    "destinations.{$index}.media",
                    $fetched,
                );
            }
        }

        return $data;
    }

    /**
     * Resolve the API/MCP media references of a post into whole hosted items.
     * An `upload_token` finds a temporary upload (the item keeps the token, so
     * the save consumes it under a row lock and a second use fails), an `id` a
     * media row of the workspace and a `url` is downloaded as a new temporary
     * upload, once per distinct URL (`$fetched` maps URL to row id and is
     * shared across the calls of one request). The save that follows moves the
     * row to its first owner and copies it for the next ones.
     *
     * `validated()` can return the items out of order when their shapes differ
     * (it fills one rule at a time), so the list is put back in index order.
     *
     * An item that cannot be resolved rejects the request (422) and deletes
     * every download of the request so far. Downloads left behind by a later
     * failure of the save stay temporary uploads that `media:prune-uploads`
     * removes.
     *
     * @param  array<MediaType>  $allowedTypes
     * @param  array<int, array<string, mixed>>  $media
     * @param  array<string, string>  $fetched
     * @return array<int, array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function execute(Workspace $workspace, array $allowedTypes, array $media, string $errorKey = 'media', array &$fetched = []): array
    {
        if ($media === []) {
            return $media;
        }

        $media = collect($media)->sortKeys()->values()->all();
        $byToken = ResolveWorkspaceMedia::byUploadTokens($workspace, self::column($media, 'upload_token'));
        $byId = ResolveWorkspaceMedia::execute($workspace, array_values(array_filter(self::column($media, 'id'), Str::isUuid(...))));

        try {
            return collect($media)->map(function (array $item, int $index) use ($workspace, $allowedTypes, $errorKey, $byToken, $byId, &$fetched): array {
                $key = "{$errorKey}.{$index}";

                if (filled($token = data_get($item, 'upload_token'))) {
                    $row = $byToken->get($token) ?? throw ValidationException::withMessages(["{$key}.upload_token" => __('posts.errors.media_expired')]);

                    return [...self::item($row, $item), 'upload_token' => $token];
                }

                if (filled($id = data_get($item, 'id'))) {
                    $row = $byId->get($id) ?? throw ValidationException::withMessages(["{$key}.id" => __('validation.exists', ['attribute' => 'media'])]);

                    return self::item($row, $item);
                }

                $url = (string) data_get($item, 'url');

                if (! array_key_exists($url, $fetched)) {
                    $upload = app(MediaAttacher::class)->hostUpload($workspace, $allowedTypes, $url)
                        ?? throw ValidationException::withMessages(["{$key}.url" => __('posts.errors.media_url_unreachable', ['url' => $url])]);

                    $fetched[$url] = $upload->id;
                }

                return self::item(Media::query()->findOrFail($fetched[$url]), $item);
            })->all();
        } catch (ValidationException $exception) {
            if ($fetched !== []) {
                DB::transaction(fn () => DeleteOwnedMedia::forRows(array_values($fetched)));
                $fetched = [];
            }

            throw $exception;
        }
    }

    /**
     * The row as the composer shape the save validates, with the client's
     * editable meta (alt text, user tags) on top of what is stored. The `alt`
     * shorthand only applies to images.
     *
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>
     */
    private static function item(Media $row, array $reference): array
    {
        $item = MediaItem::fromMedia($row)->toArray();
        $edits = array_intersect_key((array) data_get($reference, 'meta', []), array_flip(SyncOwnedMedia::EDITABLE_META));

        if ($row->type === MediaType::Image && filled($alt = data_get($reference, 'alt')) && ! array_key_exists('alt_text', $edits)) {
            $edits['alt_text'] = $alt;
        }

        $item['meta'] = [...(array) data_get($item, 'meta', []), ...$edits];

        return $item;
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @return list<string>
     */
    private static function column(array $media, string $key): array
    {
        return collect($media)->pluck($key)->filter(fn (mixed $value): bool => is_string($value) && $value !== '')->unique()->values()->all();
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Actions\Post\AppendPostMedia;
use App\Dto\MediaItem;
use App\Dto\RemoteFile;
use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;

/**
 * Downloads public URLs as temporary uploads and attaches them as media the
 * post owns — used by the MCP `AttachMediaFromUrlTool` and the REST
 * `attach-media-from-url` endpoint.
 *
 * URL syntax + DNS resolvability are validated at the request layer
 * (`url:http,https`, `active_url`). Locking, intersection of accepted
 * media types, and the JSON-column merge live on the Post model so this
 * class can stay focused on the network → MIME-validate → handoff flow.
 */
class MediaAttacher
{
    private const int TIMEOUT_SECONDS = 20;

    private const int IMAGE_TIMEOUT_SECONDS = 10;

    public function __construct(private readonly RemoteMediaImporter $importer) {}

    /**
     * @param  array<int, array{url: string, alt?: ?string}>  $urls
     * @return array{attached: array<int, array<string, mixed>>, failed: array<int, string>}
     */
    public function attachFromUrls(Post $post, array $urls, ?User $actor = null): array
    {
        $attached = [];
        $failed = [];

        foreach ($urls as $entry) {
            $url = (string) data_get($entry, 'url', '');
            $hosted = $this->hostUpload($post->workspace, $post->allowedMediaTypes(), $url);

            if ($hosted === null) {
                $failed[] = $url;

                continue;
            }

            $item = MediaItem::fromMedia($hosted)->toArray();

            if (($alt = data_get($entry, 'alt')) !== null
                && MediaType::classify(data_get($item, 'mime_type'), data_get($item, 'path')) === MediaType::Image) {
                $item['meta'] = [...data_get($item, 'meta', []), 'alt_text' => $alt];
            }

            $attached[] = $item;
        }

        if ($attached !== []) {
            AppendPostMedia::execute($post, $attached, $actor);
            $owned = collect($post->media ?? [])->keyBy('id');
            $attached = array_map(fn (array $item): array => $owned->get(data_get($item, 'id'), $item), $attached);
        }

        return ['attached' => $attached, 'failed' => $failed];
    }

    /**
     * Download one image URL as a temporary upload, aborting once the transfer
     * passes the image size cap. Null when the URL is blocked, unreachable,
     * oversized or not an image.
     */
    public function hostImage(Workspace $workspace, string $url): ?Media
    {
        $deadline = now()->addSeconds((int) config('trypost.rss_feeds.fetch_budget_seconds'));

        return $this->importer->import($workspace, $this->remoteFile($url), [MediaType::Image], self::IMAGE_TIMEOUT_SECONDS, $deadline, followRedirects: true)->media;
    }

    /**
     * Download one URL of an allowed type as a temporary upload. Null when the
     * download fails or the file is not an allowed type or is over its cap.
     *
     * @param  array<MediaType>  $allowedTypes
     */
    public function hostUpload(Workspace $workspace, array $allowedTypes, string $url): ?Media
    {
        return $this->importer->import($workspace, $this->remoteFile($url), $allowedTypes, self::TIMEOUT_SECONDS)->media;
    }

    private function remoteFile(string $url): RemoteFile
    {
        return new RemoteFile($url, basename((string) parse_url($url, PHP_URL_PATH)));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use App\Services\Brand\SafeHttpFetcher;
use RuntimeException;
use Throwable;

/**
 * Downloads public URLs and attaches them as media to a post — used by
 * the MCP `AttachMediaFromUrlTool` and the REST `attach-media-from-url`
 * endpoint.
 *
 * URL syntax + DNS resolvability are validated at the request layer
 * (`url:http,https`, `active_url`). Locking, intersection of accepted
 * media types, and the JSON-column merge live on the Post model so this
 * class can stay focused on the network → MIME-validate → handoff flow.
 */
class MediaAttacher
{
    public function __construct(private readonly SafeHttpFetcher $safeHttp) {}

    /**
     * @param  array<int, array{url: string, alt?: ?string}>  $urls
     * @return array{attached: array<int, array<string, mixed>>, failed: array<int, string>}
     */
    public function attachFromUrls(Post $post, array $urls): array
    {
        $attached = [];
        $failed = [];

        foreach ($urls as $entry) {
            $url = (string) data_get($entry, 'url', '');
            $item = $this->fetchToWorkspace($post->workspace, $post->allowedMediaTypes(), $url);

            if ($item === null) {
                $failed[] = $url;

                continue;
            }

            if (($alt = data_get($entry, 'alt')) !== null
                && MediaType::classify(data_get($item, 'mime_type'), data_get($item, 'path')) === MediaType::Image) {
                $item['meta'] = ['alt_text' => $alt];
            }

            $attached[] = $item;
        }

        if ($attached !== []) {
            $post->appendMedia($attached);
        }

        return ['attached' => $attached, 'failed' => $failed];
    }

    /**
     * Resolve an inline media array into hosted items: items with a `path` pass
     * through, external URLs are downloaded and hosted. Atomic — when any item
     * fails, media hosted in this call is rolled back so nothing is orphaned.
     *
     * @param  array<MediaType>  $allowedTypes
     * @param  array<int, array<string, mixed>>  $items
     * @return array{media: array<int, array<string, mixed>>, failed: array<int, string>}
     */
    public function resolveInlineMedia(Workspace $workspace, array $allowedTypes, array $items): array
    {
        $media = [];
        $failed = [];
        $hostedIds = [];

        foreach ($items as $item) {
            if (filled(data_get($item, 'path'))) {
                $media[] = $item;

                continue;
            }

            $url = (string) data_get($item, 'url', '');
            $hosted = $this->fetchToWorkspace($workspace, $allowedTypes, $url);

            if ($hosted === null) {
                $failed[] = $url;

                continue;
            }

            if (($meta = data_get($item, 'meta')) !== null) {
                $hosted['meta'] = $meta;
            }

            $media[] = $hosted;
            $hostedIds[] = data_get($hosted, 'id');
        }

        if ($failed !== [] && $hostedIds !== []) {
            Media::query()->whereKey($hostedIds)->get()->each->delete();
        }

        return ['media' => $media, 'failed' => $failed];
    }

    /**
     * Download a URL, validate its type, and store it on the workspace.
     *
     * @param  array<MediaType>  $allowedTypes
     * @return array<string, mixed>|null
     */
    private function fetchToWorkspace(Workspace $workspace, array $allowedTypes, string $url): ?array
    {
        $download = $this->download($url);

        if ($download === null) {
            return null;
        }

        try {
            $type = MediaType::fromMime($download['mime'] ?? '');

            if ($type === null || ! in_array($type, $allowedTypes, true)) {
                return null;
            }

            if ($download['bytes'] > $type->maxSizeInBytes()) {
                return null;
            }

            $name = basename(parse_url($url, PHP_URL_PATH) ?? '') ?: 'download.bin';
            $media = $workspace->addMediaFromPath($download['path'], $name, 'assets');

            return [
                'id' => $media->id,
                'path' => $media->path,
                'url' => $media->url,
                'type' => $media->type,
                'mime_type' => $media->mime_type,
                'original_filename' => $media->original_filename,
            ];
        } finally {
            @unlink($download['path']);
        }
    }

    /**
     * Stream the URL to a temp file, aborting once we exceed the largest
     * configured per-type cap (video). MIME is sniffed from the file's
     * magic bytes — far more reliable than trusting the upstream
     * `Content-Type` header (CDNs misconfigure, attackers spoof).
     *
     * @return array{path: string, mime: ?string, bytes: int}|null
     */
    private function download(string $url): ?array
    {
        $cap = MediaType::Video->maxSizeInBytes();
        $temp = tempnam(sys_get_temp_dir(), 'media_');

        try {
            $response = $this->safeHttp->guardedRequest($url, followRedirects: false)
                ->timeout(20)
                ->sink($temp)
                ->withOptions([
                    'progress' => static function ($total, $downloaded) use ($cap): void {
                        if ($downloaded > $cap) {
                            throw new RuntimeException('exceeded max bytes');
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable) {
            // Any fetch failure (timeout, DNS, refused, oversize) is a failed download.
            @unlink($temp);

            return null;
        }

        $bytes = filesize($temp) ?: 0;

        if (! $response->successful() || $bytes === 0) {
            @unlink($temp);

            return null;
        }

        return [
            'path' => $temp,
            'mime' => mime_content_type($temp) ?: null,
            'bytes' => $bytes,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Actions\Post\AppendPostMedia;
use App\Actions\Post\HostInlineMedia;
use App\Dto\ImportedFile;
use App\Dto\RemoteFile;
use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use Illuminate\Validation\ValidationException;

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
     * @param  array<int, array{url: string, alt?: ?string, meta?: ?array<string, mixed>}>  $urls
     * @return array{attached: array<int, array<string, mixed>>, failed: array<int, string>, failures: array<int, array{url: string, reason: string, message: string}>}
     *
     * @throws ValidationException
     */
    public function attachFromUrls(Post $post, array $urls, ?User $actor = null): array
    {
        if ($post->content_type?->acceptsMedia() === false) {
            throw ValidationException::withMessages(['urls' => __('posts.errors.media_not_accepted')]);
        }

        $attached = [];
        $failed = [];
        $failures = [];

        foreach ($urls as $entry) {
            $url = (string) data_get($entry, 'url', '');
            $imported = $this->importUpload($post->workspace, $post->allowedMediaTypes(), $url);
            $hosted = $imported->media;

            if ($hosted === null) {
                $failed[] = $url;
                $failures[] = ['url' => $url, 'reason' => (string) $imported->failure, 'message' => self::failureMessage($imported, $url)];

                continue;
            }

            $attached[] = HostInlineMedia::item($hosted, $entry);
        }

        if ($attached !== []) {
            AppendPostMedia::execute($post, $attached, $actor);
            $owned = collect($post->media ?? [])->keyBy('id');
            $attached = array_map(fn (array $item): array => $owned->get(data_get($item, 'id'), $item), $attached);
        }

        return ['attached' => $attached, 'failed' => $failed, 'failures' => $failures];
    }

    /**
     * Why a URL could not be hosted, in words an API client or agent can act on.
     */
    public static function failureMessage(ImportedFile $imported, string $url): string
    {
        return match ($imported->failure) {
            ImportedFile::TYPE_NOT_ALLOWED => __('posts.composer.media_sources.errors.type_not_allowed'),
            ImportedFile::TOO_LARGE => __('posts.composer.media_sources.errors.too_large'),
            default => __('posts.errors.media_url_unreachable', ['url' => $url]),
        };
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
        return $this->importUpload($workspace, $allowedTypes, $url)->media;
    }

    /**
     * Download one URL of an allowed type as a temporary upload, with the
     * reason when it fails.
     *
     * @param  array<MediaType>  $allowedTypes
     */
    public function importUpload(Workspace $workspace, array $allowedTypes, string $url): ImportedFile
    {
        return $this->importer->import($workspace, $this->remoteFile($url), $allowedTypes, self::TIMEOUT_SECONDS);
    }

    private function remoteFile(string $url): RemoteFile
    {
        return new RemoteFile($url, basename((string) parse_url($url, PHP_URL_PATH)));
    }
}

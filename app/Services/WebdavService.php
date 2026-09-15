<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only browser for a WebDAV share - Nextcloud, ownCloud or anything else
 * that speaks it - so media a team already keeps there can be picked in the
 * editor instead of being downloaded and uploaded again.
 *
 * The endpoint and its credentials are configured by the operator, the same way
 * S3 or R2 are. Nothing here takes a user-supplied host, which is why it may
 * talk to a service on the local network that the SSRF guard would refuse for
 * arbitrary URLs.
 */
class WebdavService
{
    /** Fallback for `services.webdav.max_import_bytes`. */
    private const DEFAULT_MAX_IMPORT_BYTES = 512 * 1024 * 1024;

    /** Files larger than this are listed but refused on import. */
    private function maxImportBytes(): int
    {
        return (int) config('services.webdav.max_import_bytes', self::DEFAULT_MAX_IMPORT_BYTES);
    }

    public function enabled(): bool
    {
        return filled(config('services.webdav.url'))
            && filled(config('services.webdav.username'))
            && filled(config('services.webdav.password'));
    }

    /**
     * One directory level, folders first, each entry relative to the
     * configured root.
     *
     * @return array{path: string, parent: ?string, entries: array<int, array<string, mixed>>}
     */
    public function list(string $path = ''): array
    {
        $path = $this->normalize($path);

        $response = $this->request()
            ->withHeaders(['Depth' => '1'])
            ->send('PROPFIND', $this->absoluteUrl($path), [
                'body' => $this->propfindBody(),
                'headers' => ['Content-Type' => 'application/xml'],
            ]);

        if ($response->failed()) {
            Log::warning('WebDAV listing failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            throw new RuntimeException('The WebDAV share could not be listed.');
        }

        return [
            'path' => $path,
            'parent' => $path === '' ? null : $this->parentOf($path),
            'entries' => $this->parseListing($response->body(), $path),
        ];
    }

    /**
     * Downloads one file into a temporary path and returns it. The caller owns
     * the file and is responsible for removing it.
     */
    public function download(string $path): string
    {
        $path = $this->normalize($path);

        if ($path === '') {
            throw new RuntimeException('No file was given.');
        }

        $url = $this->absoluteUrl($path);

        // Ask how big it is first: a share holds film rushes as readily as
        // snapshots, and one of those should be refused before it is pulled
        // across the network rather than after.
        $head = $this->request()->head($url);

        if ($head->successful() && (int) $head->header('Content-Length') > $this->maxImportBytes()) {
            throw new InvalidArgumentException('The file is too large to import.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'webdav');

        if ($temporary === false) {
            throw new RuntimeException('The downloaded file could not be stored.');
        }

        // Streamed onto disk instead of held in memory - the share decides how
        // large a file is, so the process must not have to hold one whole.
        $response = $this->request()->timeout(120)->sink($temporary)->get($url);

        if ($response->failed()) {
            @unlink($temporary);

            Log::warning('WebDAV download failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);

            throw new RuntimeException('The file could not be downloaded from the WebDAV share.');
        }

        clearstatcache(true, $temporary);

        // A share that answers no HEAD, or understates the length, lands here.
        if (filesize($temporary) > $this->maxImportBytes()) {
            @unlink($temporary);

            throw new InvalidArgumentException('The file is too large to import.');
        }

        return $temporary;
    }

    /**
     * Keeps a path inside the configured root. Traversal, absolute paths and
     * backslashes are dropped rather than rejected, so a mangled path lands on
     * the root instead of somewhere else on the server.
     */
    public function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        $segments = array_filter(
            explode('/', $path),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..'
        );

        return implode('/', $segments);
    }

    private function parentOf(string $path): string
    {
        $segments = explode('/', $path);
        array_pop($segments);

        return implode('/', $segments);
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('services.webdav.username'),
            (string) config('services.webdav.password'),
        )->timeout(30);
    }

    private function absoluteUrl(string $path): string
    {
        $base = rtrim((string) config('services.webdav.url'), '/');
        $root = $this->encodeSegments(trim((string) config('services.webdav.root', ''), '/'));
        $path = $this->encodeSegments($path);

        return implode('/', array_filter([$base, $root, $path], static fn (string $part): bool => $part !== ''));
    }

    /**
     * Encodes each segment on its own so that slashes stay separators while
     * spaces and non-ASCII names survive the round-trip.
     */
    private function encodeSegments(string $path): string
    {
        $segments = array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '');

        return implode('/', array_map('rawurlencode', $segments));
    }

    private function propfindBody(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<d:propfind xmlns:d="DAV:"><d:prop>'
            .'<d:displayname/><d:getcontentlength/><d:getcontenttype/>'
            .'<d:getlastmodified/><d:resourcetype/>'
            .'</d:prop></d:propfind>';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseListing(string $xml, string $currentPath): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw new RuntimeException('The WebDAV share returned an unreadable listing.');
        }

        // Decoded, because the hrefs coming back are: a folder called
        // "Bilder 2026" arrives with a space where the URL we built has %20,
        // and a mismatch here makes the collection list itself as its own
        // child.
        $self = rawurldecode(rtrim($this->hrefPathOf($currentPath), '/'));
        $entries = [];

        // Properties live in the DAV namespace, so every step goes through
        // children('DAV:') - plain property access does not cross namespaces
        // and silently yields nothing.
        foreach ($document->children('DAV:')->response as $response) {
            $href = rawurldecode((string) $response->children('DAV:')->href);

            // The first entry of a PROPFIND is the collection itself.
            if (rtrim($href, '/') === $self) {
                continue;
            }

            $name = basename(rtrim($href, '/'));

            if ($name === '') {
                continue;
            }

            $properties = $response->children('DAV:')->propstat->children('DAV:')->prop ?? null;

            if ($properties === null) {
                continue;
            }

            $isDirectory = isset($properties->resourcetype->children('DAV:')->collection);

            $entries[] = [
                'name' => (string) ($properties->displayname ?: $name),
                'path' => trim(($currentPath === '' ? '' : $currentPath.'/').$name, '/'),
                'is_directory' => $isDirectory,
                'size' => $isDirectory ? null : (int) $properties->getcontentlength,
                'mime_type' => $isDirectory ? null : ((string) $properties->getcontenttype ?: null),
                'modified_at' => (string) $properties->getlastmodified ?: null,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            if ($a['is_directory'] !== $b['is_directory']) {
                return $a['is_directory'] ? -1 : 1;
            }

            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $entries;
    }

    private function hrefPathOf(string $path): string
    {
        return (string) parse_url($this->absoluteUrl($path), PHP_URL_PATH);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Media\Sources;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;

/**
 * A WebDAV share - typically a Nextcloud or ownCloud folder - as a read-only
 * media source.
 *
 * The browser only ever sees paths relative to the configured root. The share
 * URL and credentials stay on the server: listings are fetched here, and the
 * import job gets the credentials as a header it never persists.
 */
class WebdavFiles
{
    public const int MAX_PATH_LENGTH = 1024;

    private const int TIMEOUT_SECONDS = 15;

    private const string PROPFIND_BODY = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
        <d:propfind xmlns:d="DAV:">
          <d:prop>
            <d:resourcetype/>
            <d:getcontenttype/>
            <d:getcontentlength/>
            <d:getlastmodified/>
          </d:prop>
        </d:propfind>
        XML;

    /**
     * One folder level: sub-folders first, then images and videos, each sorted
     * by name. Hidden entries and other file types are left out.
     *
     * @return array{path: string, entries: list<array{type: string, name: string, path: string, mime?: string, size?: int, modified?: string|null}>}
     */
    public static function browse(string $path): array
    {
        $path = self::normalize($path);

        try {
            $response = Http::withBasicAuth(self::username(), self::password())
                ->timeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(['Depth' => '1'])
                ->withBody(self::PROPFIND_BODY, 'application/xml; charset=utf-8')
                ->send('PROPFIND', self::url($path, folder: true));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The WebDAV share could not be reached.', previous: $exception);
        }

        if ($response->status() !== Response::HTTP_MULTI_STATUS) {
            throw new RuntimeException("The WebDAV share answered {$response->status()}.");
        }

        $entries = self::parse($response->body(), $path);

        usort($entries, fn (array $a, array $b): int => [$a['type'] === 'file', mb_strtolower($a['name'])] <=> [$b['type'] === 'file', mb_strtolower($b['name'])]);

        return ['path' => $path, 'entries' => $entries];
    }

    /**
     * The download the import job runs, with the share credentials attached.
     */
    public static function toRemoteFile(string $path): RemoteFile
    {
        $path = self::normalize($path);

        if ($path === '') {
            throw new InvalidArgumentException('A file path is required.');
        }

        return new RemoteFile(
            self::url($path),
            basename($path),
            ['Authorization' => 'Basic '.base64_encode(self::username().':'.self::password())],
            Source::Webdav->downloadHosts(),
            Source::Webdav,
            ['path' => $path],
        );
    }

    /**
     * A path below the root, as segments joined by "/". Empty, "." and ".."
     * segments are rejected outright rather than resolved, so a path can
     * never climb out of the configured root.
     */
    public static function normalize(string $path): string
    {
        if (mb_strlen($path) > self::MAX_PATH_LENGTH || preg_match('/[\x00-\x1F\x7F\\\\]/u', $path) === 1) {
            throw new InvalidArgumentException('Invalid WebDAV path.');
        }

        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid WebDAV path.');
            }
        }

        return $path;
    }

    /**
     * @return list<array{type: string, name: string, path: string, mime?: string, size?: int, modified?: string|null}>
     */
    private static function parse(string $xml, string $currentPath): array
    {
        $document = new DOMDocument;

        // LIBXML_NONET: never fetch anything the response refers to.
        if ($xml === '' || ! @$document->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('The WebDAV share sent an unreadable listing.');
        }

        // Anything else is not a WebDAV listing - a login page, say - and
        // must not read as an empty folder.
        if ($document->documentElement?->namespaceURI !== 'DAV:' || $document->documentElement->localName !== 'multistatus') {
            throw new RuntimeException('The WebDAV share did not answer with a listing.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');

        $basePath = self::basePath();
        $entries = [];

        foreach ($xpath->query('/d:multistatus/d:response') ?: [] as $response) {
            if (! $response instanceof DOMElement) {
                continue;
            }

            $href = rawurldecode((string) parse_url(trim((string) $xpath->evaluate('string(d:href)', $response)), PHP_URL_PATH));

            if (! str_starts_with(rtrim($href, '/').'/', $basePath.'/') && rtrim($href, '/') !== $basePath) {
                continue;
            }

            $relative = trim(substr(rtrim($href, '/'), strlen($basePath)), '/');

            // The folder itself is part of every Depth: 1 answer.
            if ($relative === $currentPath) {
                continue;
            }

            $name = basename($relative);

            if ($name === '' || str_starts_with($name, '.')) {
                continue;
            }

            $prop = 'd:propstat[contains(d:status, " 200 ")]/d:prop';

            if ($xpath->evaluate("count({$prop}/d:resourcetype/d:collection)", $response) > 0) {
                $entries[] = ['type' => 'folder', 'name' => $name, 'path' => $relative];

                continue;
            }

            $mime = self::mimeType(trim((string) $xpath->evaluate("string({$prop}/d:getcontenttype)", $response)), $name);

            if ($mime === null) {
                continue;
            }

            $modified = trim((string) $xpath->evaluate("string({$prop}/d:getlastmodified)", $response));
            $timestamp = $modified === '' ? false : strtotime($modified);

            $entries[] = [
                'type' => 'file',
                'name' => $name,
                'path' => $relative,
                'mime' => $mime,
                'size' => (int) $xpath->evaluate("string({$prop}/d:getcontentlength)", $response),
                'modified' => $timestamp === false ? null : gmdate('c', $timestamp),
            ];
        }

        return $entries;
    }

    /**
     * The reported type, or one guessed from the extension for servers that
     * leave it out. Only images and videos are offered.
     */
    private static function mimeType(string $reported, string $name): ?string
    {
        $mime = strtolower(trim(explode(';', $reported)[0]));

        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = MimeTypes::getDefault()->getMimeTypes(strtolower(pathinfo($name, PATHINFO_EXTENSION)))[0] ?? '';
        }

        return str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/') ? $mime : null;
    }

    private static function url(string $path, bool $folder = false): string
    {
        $segments = array_filter([...self::rootSegments(), ...($path === '' ? [] : explode('/', $path))], fn (string $segment): bool => $segment !== '');
        $url = rtrim((string) config('trypost.media_sources.webdav.url'), '/').'/'.implode('/', array_map('rawurlencode', $segments));

        return $folder ? rtrim($url, '/').'/' : $url;
    }

    /**
     * The decoded URL path of the root folder, without a trailing slash, which
     * is what every href in a listing starts with.
     */
    private static function basePath(): string
    {
        $sharePath = rawurldecode(rtrim((string) parse_url((string) config('trypost.media_sources.webdav.url'), PHP_URL_PATH), '/'));

        return rtrim($sharePath.'/'.implode('/', self::rootSegments()), '/');
    }

    /**
     * @return list<string>
     */
    private static function rootSegments(): array
    {
        return array_values(array_filter(explode('/', trim((string) config('trypost.media_sources.webdav.root'), '/')), fn (string $segment): bool => $segment !== ''));
    }

    private static function username(): string
    {
        return (string) config('trypost.media_sources.webdav.username');
    }

    private static function password(): string
    {
        return (string) config('trypost.media_sources.webdav.password');
    }
}

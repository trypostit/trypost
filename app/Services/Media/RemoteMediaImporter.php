<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Dto\ImportedFile;
use App\Dto\RemoteFile;
use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\Http\SafeHttpFetcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * The one way a remote file becomes a temporary upload: SSRF guard and host
 * allowlist on every hop, a byte cap while streaming, the MIME sniffed from
 * the bytes, then the per-type cap.
 */
class RemoteMediaImporter
{
    private const int MAX_FILENAME_LENGTH = 255;

    public function __construct(private readonly SafeHttpFetcher $safeHttp) {}

    /**
     * `$deadline` bounds the whole transfer, redirects included; a transfer
     * that ends past it fails as `unreachable` before anything is stored.
     * Redirects are refused unless `$followRedirects`.
     *
     * @param  array<MediaType>  $allowedTypes
     */
    public function import(Workspace $workspace, RemoteFile $file, array $allowedTypes, int $timeoutSeconds, ?CarbonInterface $deadline = null, bool $followRedirects = false): ImportedFile
    {
        if ($allowedTypes === []) {
            return ImportedFile::failed(ImportedFile::TYPE_NOT_ALLOWED);
        }

        if (! $this->hostAllowed($file->url, $file->allowedHosts)) {
            return ImportedFile::failed(ImportedFile::HOST_NOT_ALLOWED);
        }

        $download = $this->download($file, max(array_map(fn (MediaType $type): int => $type->maxSizeInBytes(), $allowedTypes)), $timeoutSeconds, $deadline, $followRedirects);

        if (is_string($download)) {
            return ImportedFile::failed($download);
        }

        try {
            if ($this->pastDeadline($deadline)) {
                return ImportedFile::failed(ImportedFile::UNREACHABLE);
            }

            $type = MediaType::fromMime((string) data_get($download, 'mime'));

            if ($type === null || ! in_array($type, $allowedTypes, true)) {
                return ImportedFile::failed(ImportedFile::TYPE_NOT_ALLOWED);
            }

            if (data_get($download, 'bytes') > $type->maxSizeInBytes()) {
                return ImportedFile::failed(ImportedFile::TOO_LARGE);
            }

            $media = $workspace->addMediaFromPath(
                data_get($download, 'path'),
                $this->filename($file->filename, (string) data_get($download, 'mime')),
                Media::COLLECTION_UPLOADS,
                $this->sourceMeta($file),
                mimeType: data_get($download, 'mime'),
            );
            $media->issueUploadToken();

            return ImportedFile::stored($media);
        } finally {
            @unlink(data_get($download, 'path'));
        }
    }

    /**
     * @param  list<string>|null  $allowedHosts
     */
    public function hostAllowed(string $url, ?array $allowedHosts): bool
    {
        if ($allowedHosts === null) {
            return true;
        }

        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        return collect($allowedHosts)
            ->map(fn (string $allowed): string => Str::lower(trim($allowed)))
            ->contains(fn (string $allowed): bool => str_starts_with($allowed, '*.')
                ? str_ends_with($host, substr($allowed, 1))
                : $host === $allowed);
    }

    /**
     * Stream the file to a temp path. A failure reason when the transfer is
     * blocked, oversized or unsuccessful.
     *
     * @return array{path: string, mime: ?string, bytes: int}|string
     */
    private function download(RemoteFile $file, int $cap, int $timeoutSeconds, ?CarbonInterface $deadline, bool $followRedirects): array|string
    {
        if ($this->pastDeadline($deadline)) {
            return ImportedFile::UNREACHABLE;
        }

        $temp = tempnam(sys_get_temp_dir(), 'media_');
        $failure = null;

        try {
            $request = $this->safeHttp->guardedRequest(
                $file->url,
                followRedirects: $followRedirects,
                deadline: $deadline,
                onRedirect: function (string $target) use ($file, &$failure): void {
                    if (! $this->hostAllowed($target, $file->allowedHosts)) {
                        $failure = ImportedFile::HOST_NOT_ALLOWED;

                        throw new RuntimeException('The redirect target is not an allowed host.');
                    }
                },
            );

            if ($file->headers !== []) {
                $request->withHeaders($file->headers);
            }

            $response = $this->safeHttp->limitTransfer(
                $request,
                $cap,
                $deadline,
                timeoutSeconds: $timeoutSeconds,
                onExceeded: function () use (&$failure): void {
                    $failure = ImportedFile::TOO_LARGE;
                },
            )->sink($temp)->get($file->url);
        } catch (Throwable) {
            @unlink($temp);

            return $failure ?? ImportedFile::UNREACHABLE;
        }

        $bytes = filesize($temp) ?: 0;

        if (! $response->successful() || $bytes === 0) {
            @unlink($temp);

            return ImportedFile::UNREACHABLE;
        }

        return [
            'path' => $temp,
            'mime' => File::mimeType($temp) ?: null,
            'bytes' => $bytes,
        ];
    }

    private function pastDeadline(?CarbonInterface $deadline): bool
    {
        return $deadline !== null && now()->greaterThanOrEqualTo($deadline);
    }

    private function filename(string $filename, string $mime): string
    {
        $name = trim(basename(str_replace('\\', '/', $filename)));

        if ($name === '' || $name === '.' || $name === '..') {
            $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';

            return "download.{$extension}";
        }

        if (mb_strlen($name) <= self::MAX_FILENAME_LENGTH) {
            return $name;
        }

        $extension = mb_substr((string) pathinfo($name, PATHINFO_EXTENSION), 0, 16);
        $suffix = $extension === '' ? '' : ".{$extension}";

        return mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, self::MAX_FILENAME_LENGTH - mb_strlen($suffix)).$suffix;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceMeta(RemoteFile $file): array
    {
        if ($file->source === null) {
            return [];
        }

        return ['source' => $file->source->value, 'source_meta' => $file->sourceMeta];
    }
}

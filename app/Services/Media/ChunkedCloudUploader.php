<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Media\Type as MediaType;
use Aws\S3\S3Client;
use finfo;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ChunkedCloudUploader
{
    private const CACHE_PREFIX = 'chunked-cloud-upload:';

    private const CACHE_TTL_HOURS = 6;

    /** S3/R2 require every non-final multipart part to be at least 5 MiB. */
    public const MIN_PART_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ?S3Client $client = null,
        private readonly ?string $bucket = null,
        private readonly ?string $disk = null,
    ) {}

    /**
     * Multipart is only needed when the disk is remote object storage and the
     * file will not be rewritten locally (videos/PDFs). Local, public, and
     * image uploads keep the assemble-then-store path.
     */
    public function shouldUseMultipart(string $fileName, ?string $disk = null): bool
    {
        if (! $this->isObjectStorageDisk($disk)) {
            return false;
        }

        $type = MediaType::fromExtension(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($type, [MediaType::Video, MediaType::Document], true);
    }

    public function isObjectStorageDisk(?string $disk = null): bool
    {
        $disk ??= $this->diskName();

        return config("filesystems.disks.{$disk}.driver") === 's3';
    }

    /**
     * @return array{done: bool, progress: int, path?: string, size?: int, mime_type?: string}
     */
    public function receiveChunk(
        string $identifier,
        string $fileName,
        string $chunk,
        int $rangeStart,
        int $rangeEnd,
        int $totalSize,
    ): array {
        $cacheKey = self::CACHE_PREFIX.$identifier;
        $chunkSize = strlen($chunk);
        $isLastChunk = ($rangeEnd + 1) >= $totalSize;

        if ($rangeEnd < $rangeStart || $chunkSize !== ($rangeEnd - $rangeStart + 1)) {
            throw new InvalidArgumentException('Chunk bytes do not match Content-Range.');
        }

        if (! $isLastChunk && $chunkSize < self::MIN_PART_BYTES) {
            throw new InvalidArgumentException(
                'Non-final multipart parts must be at least '.self::MIN_PART_BYTES.' bytes.'
            );
        }

        $state = $this->cache->get($cacheKey);

        if ($rangeStart === 0) {
            $alreadyAcceptedFirstPart = is_array($state) && (int) data_get($state, 'next_offset', 0) > 0;

            if (! $alreadyAcceptedFirstPart) {
                $this->abortIfPresent($cacheKey);
                $state = $this->startUpload($fileName, $chunk);
                $this->cache->put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));
            }
        } elseif (! is_array($state)) {
            throw new RuntimeException('Chunked cloud upload session expired or missing.');
        }

        $nextOffset = (int) data_get($state, 'next_offset', 0);

        // Idempotent replay: client retried a chunk the server already accepted.
        if ($rangeStart < $nextOffset) {
            return $this->status($state, $totalSize, completed: $nextOffset >= $totalSize);
        }

        if ($rangeStart !== $nextOffset) {
            throw new InvalidArgumentException(
                "Unexpected chunk offset {$rangeStart}, expected {$nextOffset}."
            );
        }

        $partNumber = count(data_get($state, 'parts', [])) + 1;

        $result = $this->s3()->uploadPart([
            'Bucket' => $this->bucket(),
            'Key' => data_get($state, 'key'),
            'UploadId' => data_get($state, 'upload_id'),
            'PartNumber' => $partNumber,
            'Body' => $chunk,
        ]);

        $state['parts'][] = [
            'ETag' => data_get($result, 'ETag'),
            'PartNumber' => $partNumber,
        ];
        $state['next_offset'] = $rangeEnd + 1;
        $state['bytes_received'] = (int) data_get($state, 'bytes_received', 0) + $chunkSize;

        if (! $isLastChunk) {
            $this->cache->put($cacheKey, $state, now()->addHours(self::CACHE_TTL_HOURS));

            return $this->status($state, $totalSize, completed: false);
        }

        $this->s3()->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => data_get($state, 'key'),
            'UploadId' => data_get($state, 'upload_id'),
            'MultipartUpload' => [
                'Parts' => data_get($state, 'parts', []),
            ],
        ]);

        $this->cache->forget($cacheKey);

        return $this->status($state, $totalSize, completed: true);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{done: bool, progress: int, path?: string, size?: int, mime_type?: string}
     */
    private function status(array $state, int $totalSize, bool $completed): array
    {
        $received = (int) data_get($state, 'bytes_received', 0);
        $progress = $totalSize > 0
            ? (int) round(min($received, $totalSize) / $totalSize * 100)
            : 0;

        if (! $completed) {
            return [
                'done' => false,
                'progress' => $progress,
            ];
        }

        return [
            'done' => true,
            'progress' => 100,
            'path' => (string) data_get($state, 'key'),
            'size' => $received,
            'mime_type' => (string) data_get($state, 'mime_type'),
        ];
    }

    /**
     * @return array{upload_id: string, key: string, mime_type: string, parts: array<int, mixed>, next_offset: int, bytes_received: int}
     */
    private function startUpload(string $fileName, string $firstChunk): array
    {
        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $filename = Str::uuid().".{$extension}";
        $key = "medias/{$filename}";
        $mimeType = $this->detectMimeType($firstChunk, $extension);

        $created = $this->s3()->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ContentType' => $mimeType,
        ]);

        return [
            'upload_id' => (string) data_get($created, 'UploadId'),
            'key' => $key,
            'mime_type' => $mimeType,
            'parts' => [],
            'next_offset' => 0,
            'bytes_received' => 0,
        ];
    }

    private function abortIfPresent(string $cacheKey): void
    {
        $existing = $this->cache->get($cacheKey);

        if (! is_array($existing)) {
            return;
        }

        try {
            $this->s3()->abortMultipartUpload([
                'Bucket' => $this->bucket(),
                'Key' => data_get($existing, 'key'),
                'UploadId' => data_get($existing, 'upload_id'),
            ]);
        } catch (Throwable) {
            // Best-effort cleanup of a previous incomplete upload.
        }

        $this->cache->forget($cacheKey);
    }

    private function detectMimeType(string $chunk, string $extension): string
    {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($chunk) ?: null;

        if (is_string($detected) && $detected !== 'application/octet-stream') {
            return $detected;
        }

        return MediaType::fromExtension($extension)?->allowedMimeTypes()[0]
            ?? 'application/octet-stream';
    }

    private function s3(): S3Client
    {
        if ($this->client instanceof S3Client) {
            return $this->client;
        }

        $adapter = Storage::disk($this->diskName());

        if (! $adapter instanceof AwsS3V3Adapter) {
            throw new RuntimeException('Chunked cloud uploads require an S3-compatible disk.');
        }

        return $adapter->getClient();
    }

    private function bucket(): string
    {
        if (filled($this->bucket)) {
            return $this->bucket;
        }

        return (string) config("filesystems.disks.{$this->diskName()}.bucket");
    }

    private function diskName(): string
    {
        return $this->disk ?? (string) config('filesystems.default');
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Media\Type as MediaType;
use App\Models\User;
use App\Models\Workspace;
use App\Support\VideoDurationProbe;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ChunkedAssetReceiver
{
    public function __construct(private readonly ChunkedCloudUploader $cloud) {}

    public function receive(
        Workspace $workspace,
        User $user,
        string $fileName,
        string $chunk,
        int $rangeStart,
        int $rangeEnd,
        int $totalSize,
        string $attemptId,
        ?float $duration = null,
    ): ChunkReceipt {
        $identifier = md5("{$user->id}{$fileName}{$totalSize}{$attemptId}");
        $meta = $this->videoMeta($fileName, $duration);

        return $this->cloud->shouldUseMultipart($fileName)
            ? $this->receiveViaMultipart($workspace, $identifier, $fileName, $chunk, $rangeStart, $rangeEnd, $totalSize, $meta)
            : $this->receiveViaLocalAssemble($workspace, $identifier, $fileName, $chunk, $rangeStart, $rangeEnd, $totalSize, $meta);
    }

    /**
     * @return array<string, float>
     */
    private function videoMeta(string $fileName, ?float $duration): array
    {
        if ($duration === null || $duration <= 0 || MediaType::classify(null, $fileName) !== MediaType::Video) {
            return [];
        }

        return ['duration' => round($duration, 2)];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function receiveViaMultipart(
        Workspace $workspace,
        string $identifier,
        string $fileName,
        string $chunk,
        int $rangeStart,
        int $rangeEnd,
        int $totalSize,
        array $meta,
    ): ChunkReceipt {
        $result = $this->cloud->receiveChunk(
            $identifier,
            $fileName,
            $chunk,
            $rangeStart,
            $rangeEnd,
            $totalSize,
        );

        if (! data_get($result, 'done')) {
            return ChunkReceipt::inProgress((int) data_get($result, 'progress'));
        }

        $path = (string) data_get($result, 'path');
        $mimeType = (string) data_get($result, 'mime_type');
        $size = (int) data_get($result, 'size');

        try {
            $media = $workspace->addMediaFromStoredPath(
                $path,
                $fileName,
                $mimeType,
                $size,
                'assets',
                $this->withStoredVideoDuration($meta, $mimeType, $path, $size),
            );
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }

        return ChunkReceipt::completed($media);
    }

    /**
     * The multipart object never touches local disk, so the probe reads the
     * atom headers straight from object storage with ranged GETs.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function withStoredVideoDuration(array $meta, string $mimeType, string $path, int $size): array
    {
        if (MediaType::classify($mimeType) !== MediaType::Video) {
            return $meta;
        }

        try {
            $duration = VideoDurationProbe::fromReader(
                fn (int $offset, int $length): string => $this->cloud->readRange($path, $offset, $length),
                $size,
            );
        } catch (Throwable $exception) {
            Log::warning('Could not probe video duration from object storage', ['path' => $path, 'error' => $exception->getMessage()]);

            return $meta;
        }

        return $duration === null ? $meta : [...$meta, 'duration' => round($duration, 2)];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function receiveViaLocalAssemble(
        Workspace $workspace,
        string $identifier,
        string $fileName,
        string $chunk,
        int $rangeStart,
        int $rangeEnd,
        int $totalSize,
        array $meta,
    ): ChunkReceipt {
        $tempFile = storage_path("app/private/chunks/{$identifier}");

        if (! is_dir(dirname($tempFile))) {
            mkdir(dirname($tempFile), 0755, true);
        }

        file_put_contents($tempFile, $chunk, $rangeStart === 0 ? 0 : FILE_APPEND);

        if (($rangeEnd + 1) < $totalSize) {
            return ChunkReceipt::inProgress((int) round(($rangeEnd + 1) / $totalSize * 100));
        }

        try {
            $media = $workspace->addMediaFromPath($tempFile, $fileName, 'assets', $meta);
        } finally {
            @unlink($tempFile);
        }

        return ChunkReceipt::completed($media);
    }
}

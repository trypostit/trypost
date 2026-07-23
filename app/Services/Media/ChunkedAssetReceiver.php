<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\User;
use App\Models\Workspace;
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
    ): ChunkReceipt {
        $identifier = md5($user->id.$fileName.$totalSize);

        return $this->cloud->shouldUseMultipart($fileName)
            ? $this->receiveViaMultipart($workspace, $identifier, $fileName, $chunk, $rangeStart, $rangeEnd, $totalSize)
            : $this->receiveViaLocalAssemble($workspace, $identifier, $fileName, $chunk, $rangeStart, $rangeEnd, $totalSize);
    }

    private function receiveViaMultipart(
        Workspace $workspace,
        string $identifier,
        string $fileName,
        string $chunk,
        int $rangeStart,
        int $rangeEnd,
        int $totalSize,
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

        try {
            $media = $workspace->addMediaFromStoredPath(
                $path,
                $fileName,
                (string) data_get($result, 'mime_type'),
                (int) data_get($result, 'size'),
                'assets',
            );
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }

        return ChunkReceipt::completed($media);
    }

    private function receiveViaLocalAssemble(
        Workspace $workspace,
        string $identifier,
        string $fileName,
        string $chunk,
        int $rangeStart,
        int $rangeEnd,
        int $totalSize,
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
            $media = $workspace->addMediaFromPath($tempFile, $fileName, 'assets');
        } finally {
            @unlink($tempFile);
        }

        return ChunkReceipt::completed($media);
    }
}

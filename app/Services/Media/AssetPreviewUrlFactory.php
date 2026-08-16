<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Media;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class AssetPreviewUrlFactory
{
    /**
     * @return array{preview_url: string, expires_at: string, preview_mode: string}
     */
    public function temporaryUrl(Media $media, Workspace $workspace, CarbonImmutable $expiresAt): array
    {
        $disk = (string) config('filesystems.default', 'local');
        $driver = (string) config("filesystems.disks.{$disk}.driver", 'local');

        if ($driver === 's3') {
            return [
                'preview_url' => Storage::disk($disk)->temporaryUrl($media->path, $expiresAt),
                'expires_at' => $expiresAt->utc()->toIso8601String(),
                'preview_mode' => 'temporary_url',
            ];
        }

        return [
            'preview_url' => URL::temporarySignedRoute('media.asset-preview.show', $expiresAt, [
                'workspace' => $workspace->id,
                'media' => $media->id,
            ]),
            'expires_at' => $expiresAt->utc()->toIso8601String(),
            'preview_mode' => 'signed_route',
        ];
    }

    public function ensureAvailable(Media $media): void
    {
        if (! filled($media->path) || ! Storage::disk((string) config('filesystems.default', 'local'))->exists($media->path)) {
            throw new RuntimeException('preview_unavailable');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Media;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class AssetPreviewUrlFactory
{
    /**
     * @return array{url: string, expires_at: string, mode: string}
     */
    public function temporaryUrl(Media $media, Workspace $workspace, CarbonImmutable $expiresAt): array
    {
        $disk = (string) Config::get('filesystems.default', 'local');
        $driver = (string) Config::get("filesystems.disks.{$disk}.driver", 'local');

        if (in_array($driver, ['s3'], true)) {
            return [
                'url' => Storage::disk($disk)->temporaryUrl($media->path, $expiresAt),
                'expires_at' => $expiresAt->utc()->toAtomString(),
                'mode' => 'temporary_url',
            ];
        }

        return [
            'url' => URL::temporarySignedRoute('media.asset-preview.show', $expiresAt, [
                'workspace' => $workspace->id,
                'media' => $media->id,
            ]),
            'expires_at' => $expiresAt->utc()->toAtomString(),
            'mode' => 'signed_route',
        ];
    }

    public function ensureAvailable(Media $media): void
    {
        if (! Storage::disk((string) Config::get('filesystems.default', 'local'))->exists($media->path)) {
            throw new RuntimeException('preview_unavailable');
        }
    }
}

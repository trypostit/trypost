<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Asset;

use App\Models\Media;
use App\Models\Workspace;
use App\Services\Media\AssetPreviewUrlFactory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use RuntimeException;

#[IsReadOnly]
#[Description('Return a short-lived preview URL for a current-workspace Asset Library media item.')]
class GetAssetPreviewTool extends Tool
{
    public function handle(Request $request, AssetPreviewUrlFactory $previewUrls): Response|ResponseFactory
    {
        $validated = $request->validate([
            'asset_id' => ['required', 'uuid'],
        ]);

        $media = Media::query()
            ->whereKey(data_get($validated, 'asset_id'))
            ->where('mediable_type', (new Workspace)->getMorphClass())
            ->where('mediable_id', $request->user()->current_workspace_id)
            ->where('collection', 'assets')
            ->first();

        if (! $media) {
            return Response::error('asset_not_found');
        }

        try {
            $previewUrls->ensureAvailable($media);
            $expiresAt = CarbonImmutable::now('UTC')->addMinutes(5);
            $preview = $previewUrls->temporaryUrl($media, $request->user()->currentWorkspace, $expiresAt);
        } catch (RuntimeException) {
            return Response::error('preview_unavailable');
        }

        return Response::structured([
            'asset_id' => $media->id,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size,
            'expires_at' => $preview['expires_at'],
            'preview_mode' => $preview['mode'],
            'preview_url' => $preview['url'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->string()->required()->description('UUID of the Asset Library media item to preview.'),
        ];
    }
}

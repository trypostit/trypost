<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Asset;

use App\Actions\Media\FindWorkspaceAsset;
use App\Http\Resources\Api\AssetResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
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
#[Description('Return a short-lived preview URL for an Asset Library item in the current workspace. The URL expires after a few minutes and does not expose internal storage paths.')]
class GetAssetPreviewTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request, AssetPreviewUrlFactory $previewUrls): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'createPost',
            'Not authorized to view assets.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'asset_id' => ['required', 'uuid'],
        ]);

        $asset = FindWorkspaceAsset::execute($workspace, data_get($validated, 'asset_id'));

        if (! $asset) {
            return Response::error('Asset not found.');
        }

        try {
            $previewUrls->ensureAvailable($asset);
            $preview = $previewUrls->temporaryUrl(
                $asset,
                $workspace,
                CarbonImmutable::now('UTC')->addMinutes((int) config('trypost.media.signed_preview_url_ttl_minutes')),
            );
        } catch (RuntimeException) {
            return Response::error('Asset preview is unavailable.');
        }

        return Response::structured(
            AssetResource::make($asset)->withPreview($preview)->resolve(),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->string()->required()->description('UUID of the Asset Library media item to preview.'),
        ];
    }
}

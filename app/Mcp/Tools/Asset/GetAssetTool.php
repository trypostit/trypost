<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Asset;

use App\Actions\Media\FindWorkspaceAsset;
use App\Http\Requests\Mcp\Asset\GetAssetRequest;
use App\Http\Resources\Api\AssetResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get a single Asset Library item from the current workspace.')]
class GetAssetTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'createPost',
            'Not authorized to view assets.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate((new GetAssetRequest)->rules());

        $asset = FindWorkspaceAsset::execute($workspace, data_get($validated, 'asset_id'));

        if (! $asset) {
            return Response::error('Asset not found.');
        }

        return Response::structured((new AssetResource($asset))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->string()->required()->description('UUID of the Asset Library media item.'),
        ];
    }
}

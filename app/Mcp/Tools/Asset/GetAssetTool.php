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
#[Description('Get one Asset Library item by id from the current workspace (the workspace bound to this MCP token). The id must belong to the workspace "assets" collection — logos, avatars, and other workspaces return "Asset not found." Returns id, original_filename, type (image|video|document), mime_type, size, url, meta, and created_at. Does not include the storage path. url is the stored public file URL, not a short-lived signed preview. Requires permission to create posts (viewers cannot). Use list-assets-tool to discover ids. To attach this item to a draft or scheduled post, call attach-existing-asset-tool with the same asset_id.')]
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
            'asset_id' => $schema->string()->required()->description('Required UUID of an Asset Library media item in the current workspace. Same id returned by list-assets-tool. Wrong workspace, missing id, or a non-library collection (logo/avatar) fails with "Asset not found."'),
        ];
    }
}

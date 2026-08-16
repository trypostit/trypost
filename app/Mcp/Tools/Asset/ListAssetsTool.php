<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Asset;

use App\Actions\Media\ListWorkspaceAssets;
use App\Http\Requests\Mcp\Asset\ListAssetsRequest;
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
#[Description('List Asset Library media for the current workspace (the workspace bound to this MCP token). Returns only the workspace "assets" collection — not logos or avatars. Newest first (created_at, then id). Each item includes id, original_filename, type (image|video|document), mime_type, size, url, meta, and created_at. Does not include the storage path. Use search to match original_filename (case-insensitive substring, max 255 characters) and type to keep a single media type. limit is 1–100 (default 50). has_more is true when more items exist beyond this page. Requires permission to create posts (viewers cannot list). Use get-asset-tool for one item by id, or attach-existing-asset-tool to reuse an item on a draft/scheduled post. To add new files, use request-media-upload-tool or attach-media-from-url-tool instead.')]
class ListAssetsTool extends Tool
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

        $validated = $request->validate((new ListAssetsRequest)->rules());

        $limit = (int) data_get($validated, 'limit', 50);

        $assets = ListWorkspaceAssets::query(
            $workspace,
            data_get($validated, 'search'),
            data_get($validated, 'type'),
        )->limit($limit + 1)->get();

        $hasMore = $assets->count() > $limit;

        return Response::structured([
            'assets' => AssetResource::collection($assets->take($limit))->resolve(),
            'has_more' => $hasMore,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Optional. Case-insensitive substring matched only against original_filename (not tags or captions). Maximum 255 characters. Omit to return every filename (still subject to type and limit).'),
            'type' => $schema->string()->enum(['image', 'video', 'document'])->description('Optional. Keep only this media type. Omit to include image, video, and document. Rejects unknown values such as audio.'),
            'limit' => $schema->integer()->description('Optional. Maximum number of items to return. Integer from 1 to 100. Defaults to 50 when omitted. When more matches exist, has_more is true.'),
        ];
    }
}

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
#[Description('List media from the current workspace Asset Library. Filter by filename search and type (image, video, document).')]
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

        $assets = ListWorkspaceAssets::query(
            $workspace,
            data_get($validated, 'search'),
            data_get($validated, 'type'),
        )->limit((int) data_get($validated, 'limit', 50))->get();

        return Response::structured([
            'assets' => AssetResource::collection($assets)->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Case-insensitive filename substring, maximum 255 characters.'),
            'type' => $schema->string()->enum(['image', 'video', 'document'])->description('Media type filter.'),
            'limit' => $schema->integer()->description('Max results (1-100, default 50).'),
        ];
    }
}

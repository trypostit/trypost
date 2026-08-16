<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Asset;

use App\Actions\Media\ListWorkspaceAssets;
use App\Enums\Media\Type as MediaType;
use App\Http\Resources\Api\AssetResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List media from the current workspace Asset Library. Filter by filename search and type (image, video, document). Does not include storage paths or preview URLs — use get-asset-preview-tool for a short-lived file URL.')]
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

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::enum(MediaType::class)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $assets = ListWorkspaceAssets::execute(
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

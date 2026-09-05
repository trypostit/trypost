<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Http\Resources\Api\RepurposeItemResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceRepurpose;
use App\Mcp\Requests\Repurpose\ListRepurposeItemsRequest;
use App\Models\Repurpose;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List every source video a repurpose has seen, with why each was skipped when it was: already published through TryPost, no downloadable file, or a failed download. This is where to look when a video was not replicated.')]
class ListRepurposeItemsTool extends Tool
{
    use AuthorizesMcpTool, ResolvesWorkspaceRepurpose;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageRepurposes', 'Not authorized to manage repurposes.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(ListRepurposeItemsRequest::rules());
        $repurpose = $this->repurposeInWorkspace($workspace, $validated['repurpose_id']);

        if (! $repurpose instanceof Repurpose) {
            return $repurpose;
        }

        $items = $repurpose->items()
            ->with('posts.postPlatforms:id,post_id,platform,enabled')
            ->latest()
            ->paginate(15, page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'items' => RepurposeItemResource::collection($items->items())->resolve(),
            'total' => $items->total(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repurpose_id' => $schema->string()->required()->description('The repurpose whose activity to read.'),
            'page' => $schema->integer()->description('Page number, 15 items per page.'),
        ];
    }
}

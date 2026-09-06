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
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List every source video a repurpose has seen, with why each was skipped when it was: already published through TryPost, no downloadable file, or a failed download. This is where to look when a video was not replicated.')]
#[IsReadOnly]
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
            ->orderByDesc(DB::raw('coalesce(source_created_at, created_at)'))
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'items' => RepurposeItemResource::collection($items->items())->resolve(),
            'total' => $items->total(),
            'per_page' => $items->perPage(),
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
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}

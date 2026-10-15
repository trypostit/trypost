<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\ListIdeas;
use App\Http\Resources\Api\IdeaResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Idea;
use App\Models\Workspace;
use App\Support\Requests\Idea\IdeaRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the ideas of the current workspace, newest first, paginated. Filters combine like the ideas page: stages (stage IDs) with unassigned (no stage), and labels (label IDs) with untagged (no label). Ideas cannot be turned into posts through MCP; use create-post-tool with the idea text if needed.')]
class ListIdeasTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'viewAny', Idea::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            ...IdeaRequestRules::filters(),
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $ideas = ListIdeas::query($workspace, IdeaRequestRules::filterValues($validated))
            ->with('labels')
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'ideas' => IdeaResource::collection($ideas->items())->resolve(),
            'total' => $ideas->total(),
            'per_page' => $ideas->perPage(),
            'current_page' => $ideas->currentPage(),
            'last_page' => $ideas->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'stages' => $schema->array()->items($schema->string())->description('Only ideas in these stage IDs.'),
            'unassigned' => $schema->boolean()->description('Include ideas without a stage (combines with stages).'),
            'labels' => $schema->array()->items($schema->string())->description('Only ideas with one of these label IDs.'),
            'untagged' => $schema->boolean()->description('Include ideas without labels (combines with labels).'),
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}

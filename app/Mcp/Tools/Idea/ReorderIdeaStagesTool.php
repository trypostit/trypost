<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\ReorderIdeaStages;
use App\Http\Resources\Api\IdeaStageResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\IdeaStage;
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

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Reorder the idea board stages. Pass every stage ID of the workspace in the new order.')]
class ReorderIdeaStagesTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'create', IdeaStage::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(IdeaRequestRules::reorderStages());

        ReorderIdeaStages::execute($workspace, data_get($validated, 'stage_ids'));

        return Response::structured([
            'stages' => IdeaStageResource::collection($workspace->ideaStages()->withCount('ideas')->get())->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'stage_ids' => $schema->array()->items($schema->string())->required()->description('Every stage ID of the workspace, in the new board order.'),
        ];
    }
}

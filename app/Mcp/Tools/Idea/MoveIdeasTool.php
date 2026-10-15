<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\MoveIdea;
use App\Http\Resources\Api\IdeaResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\FindsIdeaRecords;
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
#[Description('Move an idea to a stage (or to no stage) and set the order of that column. Pass in idea_ids every idea of the target stage, including the moved one, in the new order; a stale list is refused. Ideas cannot be turned into posts through MCP; use create-post-tool with the idea text if needed.')]
class MoveIdeasTool extends Tool
{
    use AuthorizesMcpTool;
    use FindsIdeaRecords;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $idea = $this->findIdea($request);

        if (! $idea) {
            return Response::error('Idea not found.');
        }

        $denied = $this->denyUnlessCan($request, 'update', $idea);

        if ($denied !== null) {
            return $denied;
        }

        $validated = $request->validate(IdeaRequestRules::move($workspace));

        MoveIdea::execute($idea, data_get($validated, 'idea_stage_id'), data_get($validated, 'idea_ids'));

        return Response::structured((new IdeaResource($idea->fresh()->load('labels')))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idea_id' => $schema->string()->required()->description('The idea ID to move.'),
            'idea_stage_id' => $schema->string()->description('The target stage ID; omit for no stage.'),
            'idea_ids' => $schema->array()->items($schema->string())->required()->description('Every idea ID of the target column, including the moved one, in the new order.'),
        ];
    }
}

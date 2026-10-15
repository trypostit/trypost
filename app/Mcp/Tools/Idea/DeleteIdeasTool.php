<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\DeleteIdeas;
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

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Delete one or more ideas (up to 500) with their media. IDs that are not in the current workspace are ignored. Ideas cannot be turned into posts through MCP; use create-post-tool with the idea text if needed.')]
class DeleteIdeasTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'create', Idea::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(IdeaRequestRules::bulk());

        DeleteIdeas::execute($workspace, data_get($validated, 'idea_ids'));

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idea_ids' => $schema->array()->items($schema->string())->required()->description('The idea IDs to delete (max 500).'),
        ];
    }
}

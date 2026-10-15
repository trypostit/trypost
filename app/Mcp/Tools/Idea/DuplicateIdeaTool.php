<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\DuplicateIdea;
use App\Http\Resources\Api\IdeaResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\FindsIdeaRecords;
use App\Models\Workspace;
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
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Duplicate an idea with its media and labels. The copy is placed right after the original in its stage. Ideas cannot be turned into posts through MCP; use create-post-tool with the idea text if needed.')]
class DuplicateIdeaTool extends Tool
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

        $copy = DuplicateIdea::execute($idea, $request->user());

        return Response::structured((new IdeaResource($copy->load('labels')))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idea_id' => $schema->string()->required()->description('The idea ID to duplicate.'),
        ];
    }
}

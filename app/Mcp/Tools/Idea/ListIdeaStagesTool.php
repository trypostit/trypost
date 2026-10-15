<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Http\Resources\Api\IdeaStageResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Idea;
use App\Models\Workspace;
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
#[Description('List the idea board stages (columns) of the current workspace in board order, with the number of ideas in each.')]
class ListIdeaStagesTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'viewAny', Idea::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $stages = $workspace->ideaStages()->withCount('ideas')->get();

        return Response::structured([
            'stages' => IdeaStageResource::collection($stages)->resolve(),
        ]);
    }
}

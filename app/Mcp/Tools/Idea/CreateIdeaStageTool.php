<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\CreateIdeaStage;
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
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Create a new idea board stage (column) at the end of the board.')]
class CreateIdeaStageTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'create', IdeaStage::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(IdeaRequestRules::storeStage());

        $stage = CreateIdeaStage::execute($workspace, $validated);

        return Response::structured((new IdeaStageResource($stage))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('The stage name (max 60 characters).'),
        ];
    }
}

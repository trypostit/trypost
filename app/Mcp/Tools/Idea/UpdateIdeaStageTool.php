<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\UpdateIdeaStage;
use App\Http\Resources\Api\IdeaStageResource;
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
#[Description('Rename an idea board stage.')]
class UpdateIdeaStageTool extends Tool
{
    use AuthorizesMcpTool;
    use FindsIdeaRecords;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $stage = $this->findIdeaStage($request);

        if (! $stage) {
            return Response::error('Idea stage not found.');
        }

        $denied = $this->denyUnlessCan($request, 'update', $stage);

        if ($denied !== null) {
            return $denied;
        }

        $validated = $request->validate(IdeaRequestRules::updateStage());

        return Response::structured((new IdeaStageResource(UpdateIdeaStage::execute($stage, $validated)))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idea_stage_id' => $schema->string()->required()->description('The stage ID.'),
            'name' => $schema->string()->required()->description('The new stage name (max 60 characters).'),
        ];
    }
}

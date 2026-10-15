<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\DeleteIdeaStage;
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
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Delete an idea board stage. Its ideas are kept and move to the unassigned column.')]
class DeleteIdeaStageTool extends Tool
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

        $denied = $this->denyUnlessCan($request, 'delete', $stage);

        if ($denied !== null) {
            return $denied;
        }

        DeleteIdeaStage::execute($stage);

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idea_stage_id' => $schema->string()->required()->description('The stage ID to delete.'),
        ];
    }
}

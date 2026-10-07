<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\CreateIdea;
use App\Http\Resources\Api\IdeaResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Idea;
use App\Models\Workspace;
use App\Support\Requests\Idea\IdeaRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create an idea. It needs a title, a body or at least one media item. Media IDs must belong to the current workspace. Ideas cannot be turned into posts through MCP; use create-post-tool with the idea text if needed.')]
class CreateIdeaTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'create', Idea::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $input = $request->all();

        $validator = Validator::make($input, IdeaRequestRules::attributes($workspace));
        IdeaRequestRules::rejectEmptyIdea($validator, $input);

        $idea = CreateIdea::execute($workspace, $request->user(), $validator->validate());

        return Response::structured((new IdeaResource($idea->load('labels')))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The idea title (max 255 characters).'),
            'body' => $schema->string()->description('The idea text, up to 10000 characters.'),
            'idea_stage_id' => $schema->string()->description('The stage ID; omit for no stage.'),
            'media_ids' => $schema->array()->items($schema->string())->description('IDs of media already in the current workspace (max 10), for example from a post media; upload tokens and URLs are not accepted.'),
            'label_ids' => $schema->array()->items($schema->string())->description('Label IDs from the current workspace.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Idea;

use App\Actions\Idea\UpdateIdea;
use App\Http\Resources\Api\IdeaResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\FindsIdeaRecords;
use App\Models\Workspace;
use App\Support\Requests\Idea\IdeaRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update an idea. Only the fields you send change; media_ids and label_ids replace the stored lists. The idea must keep a title, a body or media. Ideas cannot be turned into posts through MCP; use create-post-tool with the idea text if needed.')]
class UpdateIdeaTool extends Tool
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

        $input = collect($request->all())->except('idea_id')->all();

        $validator = Validator::make($input, IdeaRequestRules::attributes(
            $workspace,
            collect($idea->media ?? [])->pluck('id')->filter()->values()->all(),
        ));
        IdeaRequestRules::rejectEmptyIdea($validator, $input, $idea);

        return Response::structured((new IdeaResource(UpdateIdea::execute($idea, $validator->validate())->load('labels')))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'idea_id' => $schema->string()->required()->description('The idea ID.'),
            'title' => $schema->string()->description('The idea title (max 255 characters).'),
            'body' => $schema->string()->description('The idea text, up to 10000 characters.'),
            'idea_stage_id' => $schema->string()->description('Move the idea to this stage ID (placed last); to move an idea to no stage use move-ideas-tool.'),
            'media_ids' => $schema->array()->items($schema->string())->description('The full list of IDs of media already in the current workspace (max 10); upload tokens and URLs are not accepted.'),
            'label_ids' => $schema->array()->items($schema->string())->description('The full label ID list.'),
        ];
    }
}

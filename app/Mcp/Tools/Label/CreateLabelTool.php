<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Label;

use App\Actions\Label\CreateLabel;
use App\Http\Resources\Api\LabelResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Support\Requests\Label\LabelRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new label with a name and hex color.')]
class CreateLabelTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(LabelRequestRules::rules());

        $label = CreateLabel::execute($workspace, $validated);

        return Response::structured((new LabelResource($label))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('The label name.'),
            'color' => $schema->string()->required()->description('Hex color as #RRGGBB with the leading # (e.g. #FF5733).'),
        ];
    }
}

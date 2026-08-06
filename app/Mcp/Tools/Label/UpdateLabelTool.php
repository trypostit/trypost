<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Label;

use App\Actions\Label\UpdateLabel;
use App\Http\Resources\Api\LabelResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a label name or color.')]
class UpdateLabelTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'createPost',
            'Not authorized to manage labels.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'label_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $label = WorkspaceLabel::where('workspace_id', $workspace->id)
            ->find(data_get($validated, 'label_id'));

        if (! $label) {
            return Response::error('Label not found.');
        }

        $label = UpdateLabel::execute($label, $validated);

        return Response::structured((new LabelResource($label))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'label_id' => $schema->string()->required()->description('The label ID.'),
            'name' => $schema->string()->required()->description('The new name.'),
            'color' => $schema->string()->required()->description('Hex color code (e.g. #FF5733).'),
        ];
    }
}

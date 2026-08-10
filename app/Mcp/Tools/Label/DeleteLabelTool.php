<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Label;

use App\Actions\Label\DeleteLabel;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a label permanently. The label is detached from all posts that referenced it. This cannot be undone.')]
class DeleteLabelTool extends Tool
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

        $validated = $request->validate(['label_id' => ['required', 'string']]);

        $label = WorkspaceLabel::where('workspace_id', $workspace->id)
            ->find(data_get($validated, 'label_id'));

        if (! $label) {
            return Response::error('Label not found.');
        }

        DeleteLabel::execute($label);

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'label_id' => $schema->string()->required()->description('The label ID to delete.'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Signature;

use App\Actions\Signature\DeleteSignature;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Models\WorkspaceSignature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a signature permanently. This cannot be undone.')]
class DeleteSignatureTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'createPost',
            'Not authorized to manage signatures.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(['signature_id' => ['required', 'string']]);

        $signature = WorkspaceSignature::where('workspace_id', $workspace->id)
            ->find(data_get($validated, 'signature_id'));

        if (! $signature) {
            return Response::error('Signature not found.');
        }

        DeleteSignature::execute($signature);

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'signature_id' => $schema->string()->required()->description('The signature ID to delete.'),
        ];
    }
}

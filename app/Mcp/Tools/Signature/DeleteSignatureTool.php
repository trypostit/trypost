<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Signature;

use App\Actions\Signature\DeleteSignature;
use App\Mcp\Concerns\AuthorizesMcpTool;
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
        $validated = $request->validate(['signature_id' => ['required', 'string']]);

        $signature = WorkspaceSignature::where('workspace_id', $request->user()->current_workspace_id)
            ->find(data_get($validated, 'signature_id'));

        if (! $signature) {
            return Response::error('Signature not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'createPost', $request->user()->currentWorkspace, 'Not authorized to manage signatures.')) {
            return $denied;
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

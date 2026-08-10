<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Signature;

use App\Actions\Signature\UpdateSignature;
use App\Http\Resources\Api\SignatureResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Models\WorkspaceSignature;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a signature name or content.')]
class UpdateSignatureTool extends Tool
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

        $validated = $request->validate([
            'signature_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $signature = WorkspaceSignature::where('workspace_id', $workspace->id)
            ->find(data_get($validated, 'signature_id'));

        if (! $signature) {
            return Response::error('Signature not found.');
        }

        $signature = UpdateSignature::execute($signature, $validated);

        return Response::structured((new SignatureResource($signature))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'signature_id' => $schema->string()->required()->description('The signature ID.'),
            'name' => $schema->string()->required()->description('The new name.'),
            'content' => $schema->string()->required()->description('The new content (hashtags, links, custom text).'),
        ];
    }
}

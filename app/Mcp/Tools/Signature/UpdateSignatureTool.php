<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Signature;

use App\Actions\Signature\UpdateSignature;
use App\Http\Resources\Api\SignatureResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Models\WorkspaceSignature;
use App\Support\Requests\Signature\SignatureRequestRules;
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
#[Description('Update a signature name or content.')]
class UpdateSignatureTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $signature = WorkspaceSignature::where('workspace_id', $workspace->id)
            ->find(data_get($request->validate(['signature_id' => ['required', 'string', 'uuid']]), 'signature_id'));

        if (! $signature) {
            return Response::error('Signature not found.');
        }

        $validated = $request->validate(SignatureRequestRules::rules());

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

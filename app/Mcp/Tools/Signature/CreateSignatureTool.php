<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Signature;

use App\Actions\Signature\CreateSignature;
use App\Http\Resources\Api\SignatureResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Support\Requests\Signature\SignatureRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new signature with a name and content (hashtags, links, custom text, etc.). A signature is not added automatically to any post: append its content to the post content yourself.')]
class CreateSignatureTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(SignatureRequestRules::rules());

        $signature = CreateSignature::execute($workspace, $validated);

        return Response::structured((new SignatureResource($signature))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('The signature name.'),
            'content' => $schema->string()->required()->description('The signature content (hashtags, links, custom text — anything you want to append to posts).'),
        ];
    }
}

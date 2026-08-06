<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ApiKey;

use App\Models\AccessToken;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Revoke (delete) a Personal Access Token by ID. The current OAuth session token cannot be revoked through this tool. Existing integrations using the token will stop working.')]
class DeleteApiKeyTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();

        if ($user->cannot('manageTeam', $user->currentWorkspace)) {
            return Response::error('Not authorized to manage API keys.');
        }

        $validated = $request->validate(['api_key_id' => ['required', 'string']]);

        // workspace_id filter excludes OAuth-flow tokens (which have null
        // workspace_id), so the caller can't accidentally revoke their own
        // ChatGPT/MCP session token through this tool.
        $token = AccessToken::where('user_id', $user->id)
            ->where('workspace_id', $user->current_workspace_id)
            ->where('revoked', false)
            ->find(data_get($validated, 'api_key_id'));

        if (! $token) {
            return Response::error('API key not found.');
        }

        $token->forceFill(['revoked' => true])->saveQuietly();

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'api_key_id' => $schema->string()->required()->description('The API key ID to revoke.'),
        ];
    }
}

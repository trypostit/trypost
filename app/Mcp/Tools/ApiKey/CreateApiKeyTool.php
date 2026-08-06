<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ApiKey;

use App\Actions\ApiKey\CreateApiKey;
use App\Http\Resources\Api\ApiKeyResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new Personal Access Token (API key) for the current workspace. The plain token value is returned ONCE — store it immediately, it cannot be retrieved later.')]
class CreateApiKeyTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        if ($workspace === null || $user->cannot('manageTeam', $workspace)) {
            return Response::error('Not authorized to manage API keys.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => CreateApiKey::expiresAtRules(),
        ]);

        $created = CreateApiKey::execute($user, $workspace, $validated);

        return Response::structured(array_merge(
            (new ApiKeyResource($created['token']))->resolve(),
            ['token' => $created['plain_token']],
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('A human-readable name to identify the key (e.g. "My integration").'),
            'expires_at' => $schema->string()->description('Optional expiration date (YYYY-MM-DD or ISO 8601). Omit for a key that never expires.'),
        ];
    }
}

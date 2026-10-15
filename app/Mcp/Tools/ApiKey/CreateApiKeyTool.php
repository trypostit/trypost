<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ApiKey;

use App\Actions\ApiKey\CreateApiKey;
use App\Http\Resources\Api\ApiKeyResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Support\Requests\ApiKey\ApiKeyRequestRules;
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
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Create a new Personal Access Token (API key) for the current workspace. Returns `{token, plain_token}` like POST /api-keys: `token` is the key metadata and `plain_token` the secret, returned ONCE — store it immediately, it cannot be retrieved later.')]
class CreateApiKeyTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageTeam');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(ApiKeyRequestRules::store());

        $created = CreateApiKey::execute($request->user(), $workspace, $validated);

        return Response::structured([
            'token' => (new ApiKeyResource($created['token']))->resolve(),
            'plain_token' => $created['plain_token'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('A human-readable name to identify the key (e.g. "My integration").'),
            'expires_at' => $schema->string()->description('Optional expiration date (YYYY-MM-DD or ISO 8601). Omit for a key that never expires.'),
        ];
    }
}

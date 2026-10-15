<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ApiKey;

use App\Http\Resources\Api\ApiKeyResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\AccessToken;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the Personal Access Tokens (API keys) for the current workspace. Returns metadata only — the secret token value is shown only once at creation. OAuth tokens (e.g. ChatGPT MCP sessions) are excluded. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListApiKeysTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageTeam');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        // Personal access API keys only — workspace-bound MCP OAuth grants must
        // not appear here or be revocable via this tool.
        $tokens = AccessToken::where('user_id', $request->user()->id)
            ->where('workspace_id', $workspace->id)
            ->where('revoked', false)
            ->personalAccessApiKey()
            ->latest()
            ->orderByDesc('id')
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'api_keys' => ApiKeyResource::collection($tokens->items())->resolve(),
            'total' => $tokens->total(),
            'per_page' => $tokens->perPage(),
            'current_page' => $tokens->currentPage(),
            'last_page' => $tokens->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}

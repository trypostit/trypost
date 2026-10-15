<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Http\Resources\Api\WebhookResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Webhook;
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
#[Description('List outgoing webhooks for the current workspace. Signing secrets are omitted — use get-webhook-tool to read a secret. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListWebhooksTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'viewAny', Webhook::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $webhooks = Webhook::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'webhooks' => WebhookResource::collection($webhooks->items())->resolve(),
            'total' => $webhooks->total(),
            'per_page' => $webhooks->perPage(),
            'current_page' => $webhooks->currentPage(),
            'last_page' => $webhooks->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}

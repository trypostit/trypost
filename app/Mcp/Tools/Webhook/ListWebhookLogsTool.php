<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Actions\Webhook\ListWebhookLogs;
use App\Http\Resources\Api\WebhookLogResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceWebhook;
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
#[Description('List recent delivery logs for a webhook, newest first, including payload and response body. Use replay-webhook-log-tool with a log id to resend a delivery. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListWebhookLogsTool extends Tool
{
    use AuthorizesMcpTool;
    use ResolvesWorkspaceWebhook;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'webhook_id' => ['required', 'string', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $webhook = $this->webhookInWorkspace($workspace, data_get($validated, 'webhook_id'));

        if (! $webhook instanceof Webhook) {
            return $webhook;
        }

        if ($denied = $this->denyUnlessCan($request, 'view', $webhook, 'Webhook not found.')) {
            return $denied;
        }

        $logs = ListWebhookLogs::execute($webhook)
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'logs' => WebhookLogResource::collection($logs->items())->resolve(),
            'total' => $logs->total(),
            'per_page' => $logs->perPage(),
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()->required()->description('The webhook ID.'),
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}

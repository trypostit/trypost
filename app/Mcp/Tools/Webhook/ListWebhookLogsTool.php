<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Http\Resources\Api\WebhookLogResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceWebhook;
use App\Mcp\Requests\Webhook\ListWebhookLogsRequest;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List recent delivery logs for a webhook, newest first, including payload and response body. Use replay-webhook-log-tool with a log id to resend a delivery.')]
class ListWebhookLogsTool extends Tool
{
    use AuthorizesMcpTool;
    use ResolvesWorkspaceWebhook;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'manageWebhooks',
            'Not authorized to manage webhooks.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(ListWebhookLogsRequest::rules());

        $webhook = $this->webhookInWorkspace($workspace, data_get($validated, 'webhook_id'));

        if (! $webhook instanceof Webhook) {
            return $webhook;
        }

        $logs = $webhook->logs()
            ->orderByDesc('created_at')
            ->limit((int) data_get($validated, 'limit', 50))
            ->get();

        return Response::structured([
            'logs' => WebhookLogResource::collection($logs)->resolve(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()->required()->description('The webhook ID.'),
            'limit' => $schema->integer()->description('Max results (1-100, default 50).'),
        ];
    }
}

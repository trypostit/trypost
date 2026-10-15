<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Actions\Webhook\ReplayWebhookLog;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceWebhook;
use App\Models\Webhook;
use App\Models\WebhookLog;
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

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(true)]
#[Description('Replay a webhook delivery log. Dispatches a new signed delivery with a new log ID using the original payload data, even if the webhook is paused or disabled.')]
class ReplayWebhookLogTool extends Tool
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
            'log_id' => ['required', 'string', 'uuid'],
        ]);

        $webhook = $this->webhookInWorkspace($workspace, data_get($validated, 'webhook_id'));

        if (! $webhook instanceof Webhook) {
            return $webhook;
        }

        $log = $this->webhookLogFor($webhook, data_get($validated, 'log_id'));

        if (! $log instanceof WebhookLog) {
            return $log;
        }

        if ($denied = $this->denyUnlessCan($request, 'replay', [$log, $webhook], 'Webhook log not found.')) {
            return $denied;
        }

        ReplayWebhookLog::execute($webhook, $log);

        return Response::structured(['replayed' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()->required()->description('The webhook ID.'),
            'log_id' => $schema->string()->required()->description('The delivery log ID to replay.'),
        ];
    }
}

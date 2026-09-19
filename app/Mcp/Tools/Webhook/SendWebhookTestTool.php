<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Actions\Webhook\SendWebhookTest;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceWebhook;
use App\Mcp\Requests\Webhook\WebhookIdRequest;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use RuntimeException;

#[IsOpenWorld]
#[Description('Send a signed webhook.test ping to the webhook endpoint. Use this to verify the receiver is reachable. Does not create a delivery log.')]
class SendWebhookTestTool extends Tool
{
    use AuthorizesMcpTool;
    use ResolvesWorkspaceWebhook;

    public function __construct(private WebhookService $webhooks) {}

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

        $validated = $request->validate(WebhookIdRequest::rules());

        $webhook = $this->webhookInWorkspace($workspace, data_get($validated, 'webhook_id'));

        if (! $webhook instanceof Webhook) {
            return $webhook;
        }

        try {
            SendWebhookTest::execute($webhook, $this->webhooks);
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured(['tested' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()->required()->description('The webhook ID to ping.'),
        ];
    }
}

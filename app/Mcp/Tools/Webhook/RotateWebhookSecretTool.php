<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Actions\Webhook\RotateWebhookSecret;
use App\Http\Resources\Api\WebhookResource;
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

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Rotate the webhook signing secret. The previous secret stops working immediately. The new secret is returned in the response.')]
class RotateWebhookSecretTool extends Tool
{
    use AuthorizesMcpTool;
    use ResolvesWorkspaceWebhook;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(['webhook_id' => ['required', 'string', 'uuid']]);

        $webhook = $this->webhookInWorkspace($workspace, data_get($validated, 'webhook_id'));

        if (! $webhook instanceof Webhook) {
            return $webhook;
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $webhook, 'Webhook not found.')) {
            return $denied;
        }

        $webhook = RotateWebhookSecret::execute($webhook);

        return Response::structured(
            (new WebhookResource($webhook->makeVisible('signing_secret')))->resolve(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()->required()->description('The webhook ID whose secret should be rotated.'),
        ];
    }
}

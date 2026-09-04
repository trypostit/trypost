<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Actions\Webhook\UpdateWebhook;
use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use App\Http\Resources\Api\WebhookResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceWebhook;
use App\Mcp\Requests\Webhook\UpdateWebhookRequest;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Update a webhook endpoint, subscribed events, or status (enabled or disabled). Re-enabling a paused webhook resets consecutive failures. Private or local endpoints are rejected. Updating does not send a test request.')]
class UpdateWebhookTool extends Tool
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

        $validated = $request->validate(UpdateWebhookRequest::rules());

        $webhook = $this->webhookInWorkspace($workspace, data_get($validated, 'webhook_id'));

        if (! $webhook instanceof Webhook) {
            return $webhook;
        }

        try {
            $webhook = UpdateWebhook::execute(
                $webhook,
                Arr::except($validated, ['webhook_id']),
                $this->webhooks,
            );
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured((new WebhookResource($webhook))->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'webhook_id' => $schema->string()->required()->description('The webhook ID.'),
            'endpoint' => $schema->string()->description('New public URL. Private or local addresses are rejected. Omitted fields are left unchanged.'),
            'events' => $schema->array()
                ->items($schema->string()->enum(array_column(EventType::cases(), 'value')))
                ->description('Replacement event list. Must contain at least one event when provided.'),
            'status' => $schema->string()
                ->enum([Status::Enabled->value, Status::Disabled->value])
                ->description('Set to enabled or disabled. Paused webhooks can only be re-enabled, not set to paused.'),
        ];
    }
}

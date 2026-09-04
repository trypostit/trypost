<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Actions\Webhook\UpdateWebhook;
use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use App\Http\Resources\Api\WebhookResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceWebhook;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Update a webhook endpoint, subscribed events, or status (enabled or disabled). Re-enabling a paused webhook resets consecutive failures. A new endpoint is SSRF-checked but not pinged.')]
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

        $validated = $request->validate([
            'webhook_id' => ['required', 'string'],
            'endpoint' => ['sometimes', 'url', 'max:255'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::enum(EventType::class)],
            'status' => ['sometimes', 'string', Rule::enum(Status::class)->only([Status::Enabled, Status::Disabled])],
        ]);

        $webhook = $this->webhookInWorkspace($workspace, data_get($validated, 'webhook_id'));

        if (! $webhook instanceof Webhook) {
            return $webhook;
        }

        try {
            $webhook = UpdateWebhook::execute(
                $webhook,
                collect($validated)->except('webhook_id')->all(),
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
            'endpoint' => $schema->string()->description('New HTTPS URL. Omitted fields are left unchanged.'),
            'events' => $schema->array()
                ->items($schema->string()->enum(array_column(EventType::cases(), 'value')))
                ->description('Replacement event list. Must contain at least one event when provided.'),
            'status' => $schema->string()
                ->enum([Status::Enabled->value, Status::Disabled->value])
                ->description('Set to enabled or disabled. Paused webhooks can only be re-enabled, not set to paused.'),
        ];
    }
}

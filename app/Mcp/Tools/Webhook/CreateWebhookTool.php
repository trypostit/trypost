<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Actions\Webhook\CreateWebhook;
use App\Enums\Webhook\EventType;
use App\Http\Resources\Api\WebhookResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Requests\Webhook\CreateWebhookRequest;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Create an outgoing webhook for the current workspace. Returns the signing secret — it can be read later with get-webhook-tool or rotated with rotate-webhook-secret-tool. Private or local endpoints are rejected. Creating does not send a test request.')]
class CreateWebhookTool extends Tool
{
    use AuthorizesMcpTool;

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

        $validated = $request->validate(CreateWebhookRequest::rules());

        try {
            $webhook = CreateWebhook::execute($workspace, $validated, $this->webhooks);
        } catch (RuntimeException $e) {
            return Response::error($e->getMessage());
        }

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
            'endpoint' => $schema->string()->required()->description('Public URL that will receive signed webhook payloads. Private or local addresses are rejected.'),
            'events' => $schema->array()
                ->items($schema->string()->enum(array_column(EventType::cases(), 'value')))
                ->required()
                ->description('At least one event to subscribe to: post.created, post.scheduled, post.unscheduled, post.published, post.partially_published, post.failed, post.deleted.'),
        ];
    }
}

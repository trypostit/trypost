<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Actions\Webhook\CreateWebhook;
use App\Enums\Webhook\EventType;
use App\Http\Resources\Api\WebhookResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Services\WebhookService;
use App\Support\Requests\Webhook\WebhookRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Description('Create an outgoing webhook for the current workspace. Returns the signing secret — it can be read later with get-webhook-tool or rotated with rotate-webhook-secret-tool. Private or local endpoints are rejected. Creating does not send a test request. Each delivery is a JSON POST signed in the X-Webhook-Signature header (HMAC-SHA256 of the body with the signing secret). After 5 failed deliveries in a row the webhook is paused and the account owner is emailed.')]
class CreateWebhookTool extends Tool
{
    use AuthorizesMcpTool;

    public function __construct(private WebhookService $webhooks) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'create', Webhook::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(WebhookRequestRules::store());

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
            'endpoint' => $schema->string()->required()->description('Public URL, up to 255 characters, that will receive signed webhook payloads. Private or local addresses are rejected.'),
            'events' => $schema->array()
                ->items($schema->string()->enum(array_column(EventType::cases(), 'value')))
                ->required()
                ->description('At least one event to subscribe to: post.created, post.scheduled, post.unscheduled, post.published, post.failed, post.deleted.'),
        ];
    }
}

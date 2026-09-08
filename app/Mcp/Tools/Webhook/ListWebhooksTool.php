<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Webhook;

use App\Http\Resources\Api\WebhookResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Webhook;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List outgoing webhooks for the current workspace. Signing secrets are omitted — use get-webhook-tool to read a secret.')]
class ListWebhooksTool extends Tool
{
    use AuthorizesMcpTool;

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

        $webhooks = Webhook::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at')
            ->get();

        return Response::structured([
            'webhooks' => WebhookResource::collection($webhooks)->resolve(),
        ]);
    }
}

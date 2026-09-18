<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait ResolvesWorkspaceWebhook
{
    protected function webhookInWorkspace(Workspace $workspace, mixed $id): Webhook|Response|ResponseFactory
    {
        $webhook = Webhook::query()
            ->where('workspace_id', $workspace->id)
            ->find($id);

        if (! $webhook instanceof Webhook) {
            return Response::error('Webhook not found.');
        }

        return $webhook;
    }

    protected function webhookLogFor(Webhook $webhook, mixed $id): WebhookLog|Response|ResponseFactory
    {
        $log = $webhook->logs()->find($id);

        if (! $log instanceof WebhookLog) {
            return Response::error('Webhook log not found.');
        }

        return $log;
    }
}

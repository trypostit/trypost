<?php

declare(strict_types=1);

namespace App\Listeners\Webhook;

use App\Enums\Webhook\EventType;
use App\Events\PostDeleted;
use App\Models\Workspace;
use App\Services\WebhookService;

class SendPostDeletedWebhook
{
    public function __construct(private WebhookService $webhooks) {}

    public function handle(PostDeleted $event): void
    {
        $workspace = Workspace::query()->find($event->workspaceId);

        if ($workspace === null) {
            return;
        }

        $this->webhooks->dispatch($workspace, EventType::PostDeleted, [
            'id' => $event->postId,
            'workspace_id' => $event->workspaceId,
        ]);
    }
}

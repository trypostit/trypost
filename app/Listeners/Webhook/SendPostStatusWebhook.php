<?php

declare(strict_types=1);

namespace App\Listeners\Webhook;

use App\Enums\Webhook\EventType;
use App\Events\PostStatusChanged;
use App\Services\WebhookService;

class SendPostStatusWebhook
{
    public function __construct(private WebhookService $webhooks) {}

    public function handle(PostStatusChanged $event): void
    {
        $webhookEvent = EventType::fromPostStatus($event->post->status, $event->previousStatus);

        if ($webhookEvent === null) {
            return;
        }

        $workspace = $event->post->workspace;

        if ($workspace === null) {
            return;
        }

        $this->webhooks->dispatch($workspace, $webhookEvent, $this->webhooks->postPayload($event->post));
    }
}

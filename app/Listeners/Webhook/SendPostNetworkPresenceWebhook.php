<?php

declare(strict_types=1);

namespace App\Listeners\Webhook;

use App\Events\PostNetworkPresenceChanged;
use App\Services\WebhookService;

class SendPostNetworkPresenceWebhook
{
    public function __construct(private WebhookService $webhooks) {}

    public function handle(PostNetworkPresenceChanged $event): void
    {
        $workspace = $event->post->workspace;

        if (blank($workspace)) {
            return;
        }

        $this->webhooks->dispatch($workspace, $event->type, $this->webhooks->postPayload($event->post));
    }
}

<?php

declare(strict_types=1);

namespace App\Listeners\Webhook;

use App\Enums\Webhook\EventType;
use App\Events\PostCreated;
use App\Services\WebhookService;

class SendPostCreatedWebhook
{
    public function __construct(private WebhookService $webhooks) {}

    public function handle(PostCreated $event): void
    {
        $post = $event->post;
        $workspace = $post->workspace;

        if ($workspace === null) {
            return;
        }

        $this->webhooks->dispatch($workspace, EventType::PostCreated, $this->webhooks->postPayload($post));
    }
}

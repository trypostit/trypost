<?php

declare(strict_types=1);

namespace App\Listeners\Webhook;

use App\Enums\Post\Origin;
use App\Events\PostNetworkPresenceChanged;
use App\Services\WebhookService;

/**
 * A post imported from the network never sent a webhook (see ImportExternalPosts),
 * so it sends none when the network deletes it or lists it again either.
 */
class SendPostNetworkPresenceWebhook
{
    public function __construct(private WebhookService $webhooks) {}

    public function handle(PostNetworkPresenceChanged $event): void
    {
        $workspace = $event->post->workspace;

        if ($event->post->origin === Origin::Network || blank($workspace)) {
            return;
        }

        $this->webhooks->dispatch($workspace, $event->type, $this->webhooks->postPayload($event->post));
    }
}

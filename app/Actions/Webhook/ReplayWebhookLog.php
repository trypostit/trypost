<?php

declare(strict_types=1);

namespace App\Actions\Webhook;

use App\Jobs\DispatchWebhook;
use App\Models\Webhook;
use App\Models\WebhookLog;

class ReplayWebhookLog
{
    public static function execute(Webhook $webhook, WebhookLog $webhookLog): void
    {
        DispatchWebhook::dispatch(
            $webhook,
            $webhookLog->event_type,
            data_get($webhookLog->payload, 'data') ?? [],
            force: true,
        );
    }
}

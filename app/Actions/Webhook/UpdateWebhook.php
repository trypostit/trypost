<?php

declare(strict_types=1);

namespace App\Actions\Webhook;

use App\Enums\Webhook\Status;
use App\Models\Webhook;
use App\Services\WebhookService;

class UpdateWebhook
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Webhook $webhook, array $data, WebhookService $webhooks): Webhook
    {
        $endpoint = data_get($data, 'endpoint');

        if (is_string($endpoint) && $endpoint !== $webhook->endpoint) {
            $webhooks->assertEndpointAllowed($endpoint);
        }

        if (data_get($data, 'status') === Status::Enabled->value) {
            $data['consecutive_failures'] = 0;
            $data['paused_at'] = null;
        }

        $webhook->update($data);

        return $webhook->refresh();
    }
}

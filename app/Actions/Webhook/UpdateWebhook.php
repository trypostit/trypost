<?php

declare(strict_types=1);

namespace App\Actions\Webhook;

use App\Enums\Webhook\Status;
use App\Models\Webhook;
use App\Services\WebhookService;
use Illuminate\Support\Arr;

class UpdateWebhook
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Webhook $webhook, array $data, WebhookService $webhooks): Webhook
    {
        $attributes = Arr::only($data, ['endpoint', 'events', 'status']);
        $endpoint = data_get($attributes, 'endpoint');

        if (is_string($endpoint) && $endpoint !== $webhook->endpoint) {
            $webhooks->assertEndpointAllowed($endpoint);
        }

        if (data_get($attributes, 'status') === Status::Enabled->value && $webhook->status !== Status::Enabled) {
            $attributes['consecutive_failures'] = 0;
            $attributes['paused_at'] = null;
        }

        $webhook->update($attributes);

        return $webhook->refresh();
    }
}

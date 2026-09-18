<?php

declare(strict_types=1);

namespace App\Actions\Webhook;

use App\Enums\Webhook\Status;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Services\WebhookService;

class CreateWebhook
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Workspace $workspace, array $data, WebhookService $webhooks): Webhook
    {
        $endpoint = (string) data_get($data, 'endpoint');

        $webhooks->assertEndpointAllowed($endpoint);

        return Webhook::query()->create([
            'workspace_id' => $workspace->id,
            'endpoint' => $endpoint,
            'events' => data_get($data, 'events'),
            'status' => Status::Enabled,
            'signing_secret' => Webhook::generateSigningSecret(),
        ]);
    }
}

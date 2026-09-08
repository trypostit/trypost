<?php

declare(strict_types=1);

namespace App\Actions\Webhook;

use App\Models\Webhook;
use App\Services\WebhookService;

class SendWebhookTest
{
    public static function execute(Webhook $webhook, WebhookService $webhooks): void
    {
        $webhooks->ping($webhook->endpoint, $webhook->signing_secret);
    }
}

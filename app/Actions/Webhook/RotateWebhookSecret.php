<?php

declare(strict_types=1);

namespace App\Actions\Webhook;

use App\Models\Webhook;

class RotateWebhookSecret
{
    public static function execute(Webhook $webhook): Webhook
    {
        $webhook->update([
            'signing_secret' => Webhook::generateSigningSecret(),
        ]);

        return $webhook->refresh();
    }
}

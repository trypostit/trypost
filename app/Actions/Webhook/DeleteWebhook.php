<?php

declare(strict_types=1);

namespace App\Actions\Webhook;

use App\Models\Webhook;

class DeleteWebhook
{
    public static function execute(Webhook $webhook): void
    {
        $webhook->delete();
    }
}

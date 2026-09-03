<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\User;
use App\Models\Webhook;

class WebhookLogChannel
{
    public function join(User $user, Webhook $webhook): bool
    {
        return $user->can('view', $webhook);
    }
}

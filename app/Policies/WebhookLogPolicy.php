<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;

class WebhookLogPolicy
{
    public function replay(User $user, WebhookLog $webhookLog, Webhook $webhook): bool
    {
        return $webhookLog->webhook_id === $webhook->id
            && $webhook->workspace_id === $user->current_workspace_id
            && $user->can('manageWebhooks', $user->currentWorkspace);
    }
}

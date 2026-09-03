<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Webhook;

class WebhookPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentWorkspace !== null
            && $user->can('manageWebhooks', $user->currentWorkspace);
    }

    public function view(User $user, Webhook $webhook): bool
    {
        return $webhook->workspace_id === $user->current_workspace_id
            && $user->can('manageWebhooks', $user->currentWorkspace);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Webhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }

    public function delete(User $user, Webhook $webhook): bool
    {
        return $this->view($user, $webhook);
    }
}

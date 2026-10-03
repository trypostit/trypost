<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RssFeedItem;
use App\Models\User;

class RssFeedItemPolicy
{
    public function view(User $user, RssFeedItem $item): bool
    {
        return $this->update($user, $item);
    }

    public function update(User $user, RssFeedItem $item): bool
    {
        return $item->feed?->workspace_id === $user->current_workspace_id
            && $user->currentWorkspace !== null
            && $user->can('createPost', $user->currentWorkspace);
    }
}

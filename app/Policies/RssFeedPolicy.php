<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RssFeed;
use App\Models\User;

class RssFeedPolicy
{
    public function create(User $user): bool
    {
        return $user->currentWorkspace !== null
            && $user->can('createPost', $user->currentWorkspace);
    }

    public function viewAny(User $user): bool
    {
        return $this->create($user);
    }

    public function view(User $user, RssFeed $rssFeed): bool
    {
        return $this->update($user, $rssFeed);
    }

    public function update(User $user, RssFeed $rssFeed): bool
    {
        return $rssFeed->workspace_id === $user->current_workspace_id
            && $this->create($user);
    }

    public function delete(User $user, RssFeed $rssFeed): bool
    {
        return $this->update($user, $rssFeed);
    }
}

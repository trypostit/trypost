<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Idea;
use App\Models\User;

class IdeaPolicy
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

    public function view(User $user, Idea $idea): bool
    {
        return $this->update($user, $idea);
    }

    public function update(User $user, Idea $idea): bool
    {
        return $idea->workspace_id === $user->current_workspace_id
            && $this->create($user);
    }

    public function delete(User $user, Idea $idea): bool
    {
        return $this->update($user, $idea);
    }
}

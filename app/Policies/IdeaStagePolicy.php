<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IdeaStage;
use App\Models\User;

class IdeaStagePolicy
{
    public function create(User $user): bool
    {
        return $user->currentWorkspace !== null
            && $user->can('createPost', $user->currentWorkspace);
    }

    public function update(User $user, IdeaStage $stage): bool
    {
        return $stage->workspace_id === $user->current_workspace_id
            && $this->create($user);
    }

    public function delete(User $user, IdeaStage $stage): bool
    {
        return $this->update($user, $stage);
    }
}

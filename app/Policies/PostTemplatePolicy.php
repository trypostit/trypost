<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PostTemplate;
use App\Models\User;

class PostTemplatePolicy
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

    public function view(User $user, PostTemplate $template): bool
    {
        return $this->update($user, $template);
    }

    public function update(User $user, PostTemplate $template): bool
    {
        return $template->workspace_id === $user->current_workspace_id
            && $this->create($user)
            && $template->isEditableBy($user);
    }

    public function delete(User $user, PostTemplate $template): bool
    {
        return $this->update($user, $template);
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Repurpose;
use App\Models\User;

class RepurposePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentWorkspace !== null
            && $user->can('manageRepurposes', $user->currentWorkspace);
    }

    public function view(User $user, Repurpose $repurpose): bool
    {
        return $repurpose->workspace_id === $user->current_workspace_id
            && $user->can('manageRepurposes', $user->currentWorkspace);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Repurpose $repurpose): bool
    {
        return $this->view($user, $repurpose);
    }

    public function delete(User $user, Repurpose $repurpose): bool
    {
        return $this->view($user, $repurpose);
    }
}

<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasWorkspace
{
    /**
     * Get all workspaces the user belongs to (as owner or member).
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'user_workspace')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Get the user's current workspace.
     */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /**
     * Switch to a different workspace.
     */
    public function switchWorkspace(Workspace $workspace): void
    {
        $this->update(['current_workspace_id' => $workspace->id]);
    }

    /**
     * Check if user belongs to a workspace on their current account.
     */
    public function belongsToWorkspace(Workspace $workspace): bool
    {
        if ($workspace->account_id !== $this->account_id) {
            return false;
        }

        return $this->workspaces()->where('workspaces.id', $workspace->id)->exists();
    }

    /**
     * Workspaces the user can use on their current account (never cross-account).
     *
     * @return BelongsToMany<Workspace, $this>
     */
    public function accountWorkspaces(): BelongsToMany
    {
        return $this->workspaces()
            ->where('workspaces.account_id', $this->account_id);
    }

    /**
     * Get the count of workspaces the user owns.
     */
    public function ownedWorkspacesCount(): int
    {
        return Workspace::where('user_id', $this->id)->count();
    }
}

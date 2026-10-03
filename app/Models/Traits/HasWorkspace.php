<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

trait HasWorkspace
{
    /**
     * Get all workspaces the user belongs to (as owner or member).
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'user_workspace')
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

    /**
     * The account owner always manages every workspace of the account.
     */
    public function ownsAccountOf(Workspace $workspace): bool
    {
        return $workspace->account_id === $this->account_id && $this->isAccountOwner();
    }

    /**
     * The user's access to the workspace from one membership read: the owner is
     * always an admin, and only a member who is not an admin can need approval.
     * Users outside the workspace get no access and are never gated.
     *
     * @return array{is_owner: bool, is_admin: bool, requires_approval: bool}
     */
    public function accessIn(Workspace $workspace): array
    {
        $isOwner = $this->ownsAccountOf($workspace);
        $pivot = $isOwner ? null : $this->workspacePivot($workspace);
        $isAdmin = $isOwner || (bool) $pivot?->is_admin;

        return [
            'is_owner' => $isOwner,
            'is_admin' => $isAdmin,
            'requires_approval' => ! $isAdmin && (bool) $pivot?->requires_approval,
        ];
    }

    public function isWorkspaceAdmin(Workspace $workspace): bool
    {
        return $this->accessIn($workspace)['is_admin'];
    }

    public function requiresApprovalIn(Workspace $workspace): bool
    {
        return $this->accessIn($workspace)['requires_approval'];
    }

    public function canPublishDirectlyIn(Workspace $workspace): bool
    {
        return $this->publishesDirectlyThrough($workspace, $this->workspacePivot($workspace));
    }

    /**
     * The direct-publishing rule for an already-loaded membership pivot.
     */
    public function publishesDirectlyThrough(Workspace $workspace, ?Pivot $pivot): bool
    {
        if ($this->ownsAccountOf($workspace)) {
            return true;
        }

        return $pivot !== null
            && $workspace->account_id === $this->account_id
            && ((bool) $pivot->is_admin || ! (bool) $pivot->requires_approval);
    }

    private function workspacePivot(Workspace $workspace): ?Pivot
    {
        if ($workspace->account_id !== $this->account_id) {
            return null;
        }

        return $workspace->members()->where('users.id', $this->id)->first()?->pivot;
    }
}

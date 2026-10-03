<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PostPolicy
{
    /**
     * Authorize viewing a post. The post must live in the user's current
     * workspace; cross-workspace lookups deny as 404 so we don't leak
     * existence across tenants.
     */
    public function view(User $user, Post $post): bool|Response
    {
        if ($post->workspace_id !== $user->current_workspace_id) {
            return Response::denyAsNotFound();
        }

        return true;
    }

    /**
     * Authorize updating a post: tenancy guard (404 across tenants) then the
     * `createPost` gate (any member). Whether the change needs approval is
     * decided by PostApproval when it is saved.
     */
    public function update(User $user, Post $post): bool|Response
    {
        if ($post->workspace_id !== $user->current_workspace_id) {
            return Response::denyAsNotFound();
        }

        return $user->can('createPost', $user->currentWorkspace);
    }

    /**
     * Authorize deleting a post: tenancy guard (404 across tenants) then the
     * same `createPost` gate as `update`.
     */
    public function delete(User $user, Post $post): bool|Response
    {
        if ($post->workspace_id !== $user->current_workspace_id) {
            return Response::denyAsNotFound();
        }

        return $user->can('createPost', $user->currentWorkspace);
    }

    /**
     * Authorize duplicating a post into the user's current workspace as a
     * fresh draft. The post must live in the user's current workspace
     * (404 otherwise — tenancy guard, see `view()`) AND the user must
     * have permission to create posts there (403 otherwise).
     */
    public function duplicate(User $user, Post $post): bool|Response
    {
        if ($post->workspace_id !== $user->current_workspace_id) {
            return Response::denyAsNotFound();
        }

        return $user->can('createPost', $user->currentWorkspace);
    }

    /**
     * Authorize approving or rejecting a pending post: tenancy guard (404 across
     * tenants) then the approver gate (members who publish directly).
     */
    public function approve(User $user, Post $post): bool|Response
    {
        if ($post->workspace_id !== $user->current_workspace_id) {
            return Response::denyAsNotFound();
        }

        return $user->can('approvePosts', $user->currentWorkspace);
    }
}

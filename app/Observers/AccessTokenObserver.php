<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\User;

class AccessTokenObserver
{
    /**
     * OAuth MCP grants unlock the MCP onboarding step for the whole account.
     */
    public function created(AccessToken $accessToken): void
    {
        $this->broadcastIfMcpOAuth($accessToken);
    }

    public function updated(AccessToken $accessToken): void
    {
        if (! $accessToken->wasChanged('revoked')) {
            return;
        }

        $this->broadcastIfMcpOAuth($accessToken);
    }

    private function broadcastIfMcpOAuth(AccessToken $accessToken): void
    {
        // Ignore revocation mid-flight so disconnect still clears residual.
        $looksLikeMcp = in_array('mcp:use', $accessToken->scopes ?? [], true)
            && ! $accessToken->isPersonalAccessToken();

        if (! $looksLikeMcp) {
            return;
        }

        $user = User::query()
            ->with(['account', 'currentWorkspace'])
            ->find($accessToken->user_id);

        if ($user?->account === null) {
            return;
        }

        // Revocation always notifies so residual can clear. Live grants only
        // unlock the step when the token is bound and the user can create posts
        // (viewers with read-only MCP must not complete the checklist).
        if (! $accessToken->revoked && ! $this->unlocksMcpChecklist($accessToken, $user)) {
            return;
        }

        OnboardingStatusUpdated::dispatchForAccount($user->account, $user);
    }

    private function unlocksMcpChecklist(AccessToken $accessToken, User $user): bool
    {
        $workspace = $accessToken->workspace;

        return $workspace !== null
            && $accessToken->isUsableMcpGrant($user, $workspace)
            && $user->can('createPost', $workspace);
    }
}

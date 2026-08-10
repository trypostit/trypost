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
        if ($accessToken->wasChanged('revoked')) {
            $this->broadcastIfMcpOAuth($accessToken);
        }
    }

    private function broadcastIfMcpOAuth(AccessToken $accessToken): void
    {
        if (! $this->looksLikeMcpOAuth($accessToken)) {
            return;
        }

        $user = User::query()
            ->with(['account', 'currentWorkspace'])
            ->find($accessToken->user_id);

        if ($user === null) {
            return;
        }

        // Revocation always notifies so progress can clear. Live grants only
        // unlock when bound + createPost (viewers must not complete the checklist).
        if ($accessToken->revoked || $accessToken->unlocksOnboardingChecklist($user)) {
            OnboardingStatusUpdated::dispatchForAccount($user->accountOrFail(), $user);
        }
    }

    private function looksLikeMcpOAuth(AccessToken $accessToken): bool
    {
        // Ignore personal-access tokens; treat mid-revoke rows as MCP so
        // disconnect still clears progress.
        return in_array('mcp:use', $accessToken->scopes ?? [], true)
            && ! $accessToken->isPersonalAccessToken();
    }
}

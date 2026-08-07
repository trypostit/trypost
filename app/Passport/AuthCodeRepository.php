<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * Persists the chosen workspace on the auth code so the subsequent token
 * exchange (no browser session) can bind the access token.
 *
 * Requires an explicit `workspace_id` from the consent form (membership-
 * checked). No current-workspace fallback — silent re-consent is disabled
 * so the picker always runs.
 */
class AuthCodeRepository extends PassportAuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'workspace_id' => $this->resolveWorkspaceId($authCodeEntity->getUserIdentifier()),
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ])->save();
    }

    private function resolveWorkspaceId(?string $userId): ?string
    {
        $user = Auth::user();

        if (! $user instanceof User && $userId) {
            $user = User::query()->find($userId);
        }

        if (! $user instanceof User) {
            return null;
        }

        $requestedId = request()->string('workspace_id');

        if ($requestedId->isEmpty()) {
            return null;
        }

        $workspace = Workspace::query()->find($requestedId->value());

        if ($workspace === null || ! $user->belongsToWorkspace($workspace)) {
            return null;
        }

        return $workspace->id;
    }
}

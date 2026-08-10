<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Persists the chosen workspace on the auth code so the subsequent token
 * exchange (no browser session) can bind the access token.
 *
 * Requires an explicit `workspace_id` from the consent form (membership-
 * checked). No current-workspace fallback — silent re-consent is disabled
 * so the picker always runs. Missing/invalid workspace fails here (during
 * approve) instead of issuing a code that dies at token exchange.
 */
class AuthCodeRepository extends PassportAuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $workspaceId = $this->resolveWorkspaceId($authCodeEntity->getUserIdentifier());

        if ($workspaceId === null) {
            throw OAuthServerException::invalidRequest(
                'workspace_id',
                'Unable to bind this connection to a workspace. Reconnect from a workspace you belong to.',
            );
        }

        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'workspace_id' => $workspaceId,
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

<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Passport\Bridge\AccessTokenRepository as PassportAccessTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Persists workspace_id on non-personal-access OAuth tokens at issue time —
 * same seam as AuthCodeRepository for consent codes. Personal-access tokens
 * stay null so CreateApiKey (and friends) can bind afterward.
 *
 * Authorization-code grants use only the auth code's workspace (no current-
 * workspace fallback). Refresh grants inherit the refreshed token's workspace
 * only when the user still belongs to it. Unknown grant types fail closed.
 */
class AccessTokenRepository extends PassportAccessTokenRepository
{
    public function __construct(
        Dispatcher $events,
        private OAuthPayloadDecryptor $decryptor,
    ) {
        parent::__construct($events);
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $id = $accessTokenEntity->getIdentifier();
        $userId = $accessTokenEntity->getUserIdentifier();
        $clientId = $accessTokenEntity->getClient()->getIdentifier();
        $workspaceId = null;

        if ($this->clientRequiresWorkspace($clientId)) {
            $workspaceId = $this->resolveWorkspaceId($userId);

            if ($workspaceId === null) {
                throw OAuthServerException::invalidGrant(
                    'Unable to bind this connection to a workspace. Reconnect from a workspace you belong to.',
                );
            }
        }

        Passport::token()->forceFill([
            'id' => $id,
            'user_id' => $userId,
            'client_id' => $clientId,
            'workspace_id' => $workspaceId,
            'scopes' => $accessTokenEntity->getScopes(),
            'revoked' => false,
            'expires_at' => $accessTokenEntity->getExpiryDateTime(),
        ])->save();

        $this->events->dispatch(new AccessTokenCreated($id, $userId, $clientId));
    }

    private function clientRequiresWorkspace(string $clientId): bool
    {
        $client = Passport::client()->newQuery()->find($clientId);

        return $client !== null && ! $client->hasGrantType('personal_access');
    }

    private function resolveWorkspaceId(?string $userId): ?string
    {
        $user = $userId ? User::query()->find($userId) : null;

        return match (request('grant_type')) {
            'refresh_token' => $this->ownedWorkspace(
                $user,
                AccessToken::query()
                    ->find($this->payloadId('refresh_token', 'access_token_id'))
                    ?->workspace_id,
            ),
            'authorization_code' => $this->ownedWorkspace(
                $user,
                AuthCode::query()->find($this->payloadId('code', 'auth_code_id'))?->workspace_id,
            ),
            default => null,
        };
    }

    private function ownedWorkspace(?User $user, mixed $workspaceId): ?string
    {
        if ($user === null || blank($workspaceId)) {
            return null;
        }

        $workspace = Workspace::query()->find($workspaceId);

        return $workspace && $user->belongsToWorkspace($workspace)
            ? $workspace->id
            : null;
    }

    private function payloadId(string $input, string $key): mixed
    {
        $encrypted = request($input);

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        return data_get($this->decryptor->decrypt($encrypted), $key) ?: null;
    }
}

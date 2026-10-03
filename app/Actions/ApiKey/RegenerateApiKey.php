<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RegenerateApiKey
{
    /**
     * Revokes the key and issues a new one with the same name. A future expiry
     * carries over; a past one does not, so the new key always works.
     *
     * @return array{token: AccessToken, plain_token: string}
     */
    public static function execute(User $user, Workspace $workspace, AccessToken $token): array
    {
        return DB::transaction(function () use ($user, $workspace, $token): array {
            $token->forceFill(['revoked' => true])->saveQuietly();

            return CreateApiKey::execute($user, $workspace, [
                'name' => $token->name,
                'expires_at' => $token->expires_at?->isFuture()
                    ? $token->expires_at->toDateString()
                    : null,
            ]);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RevokeAccessTokens
{
    /**
     * @param  Collection<int, AccessToken>|list<AccessToken>|AccessToken  $tokens
     */
    public static function execute(Collection|array|AccessToken $tokens): void
    {
        $tokens = Collection::wrap($tokens)
            ->filter(fn (mixed $token): bool => $token instanceof AccessToken)
            ->values();

        if ($tokens->isEmpty()) {
            return;
        }

        $tokenIds = $tokens->pluck('id');

        DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', $tokenIds)
            ->update(['revoked' => true]);

        $tokens->each(function (AccessToken $token): void {
            if ($token->revoked) {
                return;
            }

            $token->revoke();
        });
    }
}

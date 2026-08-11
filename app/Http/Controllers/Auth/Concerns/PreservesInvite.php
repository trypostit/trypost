<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;

/**
 * Carries the invite id across the OAuth round-trip via session, mirroring
 * PreservesAttributionParameters.
 */
trait PreservesInvite
{
    private function storeInvite(Request $request): void
    {
        $inviteId = $request->string('invite');

        if ($inviteId->isNotEmpty()) {
            $request->session()->put('oauth_invite_id', $inviteId->toString());
        }
    }

    private function retrieveInvite(): ?string
    {
        return session()->pull('oauth_invite_id');
    }
}

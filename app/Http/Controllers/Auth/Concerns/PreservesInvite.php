<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;

/**
 * Mirrors PreservesAttributionParameters, but for the invite id that the
 * email/password flow already threads through a hidden form field (see
 * AcceptInvite.vue -> Register.vue/Login.vue). The OAuth round-trip has no
 * form to carry it in, so it rides the session instead — same problem
 * PreservesAttributionParameters solves, different (non-marketing) data, so
 * it stays a separate trait/session key rather than overloading that one.
 *
 * Only the id is preserved — never a redirect URL. The return-to-invite URL
 * is always derived server-side from a DB-validated Invite (see
 * Invite::fromId()), so there is nothing here for a client to redirect to
 * an arbitrary destination with.
 */
trait PreservesInvite
{
    private function storeInvite(Request $request): void
    {
        $inviteId = $request->query('invite');

        if (is_string($inviteId) && $inviteId !== '') {
            $request->session()->put('oauth_invite_id', $inviteId);
        }
    }

    private function retrieveInvite(): ?string
    {
        return session()->pull('oauth_invite_id');
    }
}

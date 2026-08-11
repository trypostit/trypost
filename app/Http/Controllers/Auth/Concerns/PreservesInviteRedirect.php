<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;

/**
 * Mirrors PreservesAttributionParameters, but for the invite/redirect pair
 * that the email/password flow already threads through hidden form fields
 * (see AcceptInvite.vue -> Register.vue). The OAuth round-trip has no form to
 * carry those in, so they ride the session instead — same problem
 * PreservesAttributionParameters solves, different (non-marketing) data, so
 * it stays a separate trait/session key rather than overloading that one.
 */
trait PreservesInviteRedirect
{
    private function storeInviteRedirect(Request $request): void
    {
        $data = array_filter([
            'redirect' => $request->query('redirect'),
            'invite' => $request->query('invite'),
        ], 'is_string');

        if ($data !== []) {
            $request->session()->put('oauth_invite_redirect', $data);
        }
    }

    /**
     * @return array{redirect?: string, invite?: string}
     */
    private function retrieveInviteRedirect(): array
    {
        return session()->pull('oauth_invite_redirect', []);
    }
}

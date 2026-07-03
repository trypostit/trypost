<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailVerified
{
    /**
     * Email signups can't reach the app before confirming their address: the
     * verification link arrives by email and logs the user in on its own (magic
     * link). Social logins and invites are already verified, so they never see
     * this gate.
     *
     * Applies only to page navigation (non-JSON GET); self-hosted skips it,
     * since an instance may not even have mail configured.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('trypost.self_hosted')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        if ($request->routeIs('verification.*', 'logout', 'register.success')) {
            return $next($request);
        }

        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return $next($request);
        }

        return redirect()->route('verification.notice');
    }
}

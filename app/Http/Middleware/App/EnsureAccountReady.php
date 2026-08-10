<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountReady
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! config('trypost.self_hosted')) {
            $account = $user->account;

            if (! $account?->hasAppAccess()) {
                // Members can't finish Welcome checkout — send them straight to
                // the hold screen instead of hopping through persona first.
                if (! $user->isAccountOwner()) {
                    return redirect()->route('app.welcome.subscription-required');
                }

                return redirect()->route('app.welcome.persona');
            }
        }

        return $next($request);
    }
}

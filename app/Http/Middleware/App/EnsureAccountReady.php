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
                return redirect()->route('app.onboarding');
            }
        }

        return $next($request);
    }
}

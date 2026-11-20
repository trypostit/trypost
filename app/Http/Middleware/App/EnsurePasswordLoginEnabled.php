<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Support\Auth\LoginMethods;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Closes the password routes when the instance runs on an identity provider
 * only. Hiding the form in the UI is not enough - the endpoints have to be
 * shut as well, or the form can simply be posted to directly.
 */
class EnsurePasswordLoginEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! LoginMethods::passwordEnabled()) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}

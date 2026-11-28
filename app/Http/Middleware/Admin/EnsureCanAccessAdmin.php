<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanAccessAdmin
{
    /**
     * Let only admins (an account whose email is in `ADMIN_EMAILS`,
     * verified or not) through; everyone else gets a 403.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canAccessAdmin() === true, Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}

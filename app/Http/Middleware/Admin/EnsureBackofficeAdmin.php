<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBackofficeAdmin
{
    /**
     * Let only backoffice admins (`ADMIN_EMAILS`, verified) through; everyone
     * else gets a 403.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isBackofficeAdmin() === true, Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}

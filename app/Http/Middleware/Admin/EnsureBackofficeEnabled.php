<?php

declare(strict_types=1);

namespace App\Http\Middleware\Admin;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBackofficeEnabled
{
    /**
     * The backoffice is TryPost Cloud's only: a self-hosted install answers
     * 404 on every backoffice page, as if it were not there.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if((bool) config('trypost.self_hosted'), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}

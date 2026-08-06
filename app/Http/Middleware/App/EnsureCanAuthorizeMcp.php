<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Actions\AccessToken\RevokeMcpOAuthGrants;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanAuthorizeMcp
{
    /**
     * Block OAuth consent approval when the user cannot create posts anywhere
     * (viewers would otherwise mint orphan mcp:use grants).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! RevokeMcpOAuthGrants::canCreatePostSomewhere($user)) {
            abort(Response::HTTP_FORBIDDEN, __('mcp.authorize_denied_title'));
        }

        return $next($request);
    }
}

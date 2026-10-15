<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mcp;

use App\Support\Mcp\HostedMcpClient;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

class AuthenticateMcp extends Authenticate
{
    /**
     * @param  Request  $request
     */
    public function handle($request, Closure $next, ...$guards): mixed
    {
        $request->attributes->set(HostedMcpClient::TOKEN_SENT, filled($request->bearerToken()));

        return parent::handle($request, $next, ...$guards);
    }

    /**
     * ChatGPT and Claude may connect and list tools before the user signs in,
     * so they can offer sign-in inside the conversation when a tool is
     * called. Everything else, and every request that carries a token, is
     * refused with the usual 401.
     *
     * @param  array<int, string|null>  $guards
     */
    protected function unauthenticated($request, array $guards): void
    {
        if (HostedMcpClient::allowsAnonymousDiscovery($request)) {
            return;
        }

        parent::unauthenticated($request, $guards);
    }
}

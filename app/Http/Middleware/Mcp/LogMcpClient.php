<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogMcpClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenSent = filled($request->bearerToken());

        $response = $next($request);

        Log::info('MCP request', [
            'method' => $request->json('method'),
            'tool' => $request->json('params.name'),
            'client' => $request->json('params.clientInfo.name'),
            'client_version' => $request->json('params.clientInfo.version'),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'has_token' => $tokenSent,
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }
}

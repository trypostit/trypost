<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogMcpClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenSent = filled($request->bearerToken());

        $response = $next($request);

        Log::info('MCP request', [
            'method' => self::text($request->json('method')),
            'tool' => self::text($request->json('params.name')),
            'client' => self::text($request->json('params.clientInfo.name')),
            'client_version' => self::text($request->json('params.clientInfo.version')),
            'user_agent' => self::text($request->userAgent()),
            'ip' => $request->ip(),
            'has_token' => $tokenSent,
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    /**
     * Client-supplied values are logged as short strings only.
     */
    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? Str::limit((string) $value, 200) : null;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mcp;

use App\Support\Mcp\ChatGptMcpClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChallengeChatGptToolCalls
{
    private const MESSAGE = 'Sign in to TryPost to continue.';

    /**
     * ChatGPT shows its sign-in prompt in the conversation only for a tool
     * result carrying _meta["mcp/www_authenticate"], never for an HTTP 401
     * (https://developers.openai.com/plugins/build/auth). A refused tool
     * call from ChatGPT is answered in that shape; every other client keeps
     * the 401 it signs in from.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== Response::HTTP_UNAUTHORIZED
            || $request->json('method') !== 'tools/call'
            || ! ChatGptMcpClient::matches($request)) {
            return $response;
        }

        $message = self::MESSAGE;
        $resourceMetadata = route('mcp.oauth.protected-resource.nested', ['path' => $request->path()]);

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $request->json('id'),
            'result' => [
                'content' => [['type' => 'text', 'text' => $message]],
                'isError' => true,
                '_meta' => [
                    'mcp/www_authenticate' => [
                        "Bearer resource_metadata=\"{$resourceMetadata}\", error=\"insufficient_scope\", error_description=\"{$message}\"",
                    ],
                ],
            ],
        ], Response::HTTP_OK);
    }
}

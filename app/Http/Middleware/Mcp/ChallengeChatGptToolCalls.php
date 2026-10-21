<?php

declare(strict_types=1);

namespace App\Http\Middleware\Mcp;

use App\Enums\Mcp\Method;
use App\Support\Mcp\ChatGptMcpClient;
use Closure;
use Illuminate\Http\Request;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
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

        if ($response->getStatusCode() !== Response::HTTP_UNAUTHORIZED || ! ChatGptMcpClient::matches($request)) {
            return $response;
        }

        $call = rescue(fn (): JsonRpcRequest => JsonRpcRequest::from($request->json()->all()), report: false);

        if ($call?->method !== Method::ToolsCall->value) {
            return $response;
        }

        return response()->json($this->signInPrompt($call, $request)->toArray());
    }

    private function signInPrompt(JsonRpcRequest $call, Request $request): JsonRpcResponse
    {
        $message = self::MESSAGE;
        $resourceMetadata = route('mcp.oauth.protected-resource.nested', ['path' => $request->path()]);

        return JsonRpcResponse::result($call->id, [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
            '_meta' => [
                'mcp/www_authenticate' => [
                    "Bearer resource_metadata=\"{$resourceMetadata}\", error=\"insufficient_scope\", error_description=\"{$message}\"",
                ],
            ],
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Methods;

use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

class ListToolsWithSecuritySchemes extends ListTools
{
    /**
     * Every tool needs the user's account, so each one declares OAuth with
     * the mcp:use scope. ChatGPT offers sign-in in the conversation only for
     * tools that declare it (https://developers.openai.com/apps-sdk/build/auth);
     * other clients ignore the field.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        $result = data_get(parent::handle($request, $context)->toArray(), 'result', []);

        $result['tools'] = collect(data_get($result, 'tools', []))
            ->map(fn (array $tool): array => [
                ...$tool,
                'securitySchemes' => [['type' => 'oauth2', 'scopes' => ['mcp:use']]],
            ])
            ->all();

        return JsonRpcResponse::result($request->id, $result);
    }
}

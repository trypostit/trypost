<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);

    $this->user = $result['user'];
    $this->workspace = $result['workspace'];

    Log::spy();
});

test('an MCP request without a token is logged with its client and status', function () {
    $this->withHeaders([
        'Accept' => 'application/json, text/event-stream',
        'User-Agent' => 'Cursor/1.7.0',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-03-26',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'cursor-vscode', 'version' => '1.0.0'],
        ],
    ])->assertUnauthorized();

    Log::shouldHaveReceived('info')->once()->with('MCP request', [
        'method' => 'initialize',
        'tool' => null,
        'client' => 'cursor-vscode',
        'client_version' => '1.0.0',
        'user_agent' => 'Cursor/1.7.0',
        'ip' => '127.0.0.1',
        'has_token' => false,
        'status' => 401,
    ]);
});

test('an MCP tool call with a token is logged without the token', function () {
    $token = mcpBearerToken($this->user, $this->workspace)['plain_token'];

    $this->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
        'User-Agent' => 'Claude-User',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'get-workspace-tool', 'arguments' => (object) []],
    ])->assertSuccessful();

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context) use ($token): bool {
        return $message === 'MCP request'
            && $context['method'] === 'tools/call'
            && $context['tool'] === 'get-workspace-tool'
            && $context['user_agent'] === 'Claude-User'
            && $context['has_token'] === true
            && $context['status'] === 200
            && ! str_contains(json_encode($context), $token);
    });
});

test('an MCP request with an invalid token is logged as carrying a token', function () {
    $this->withHeaders([
        'Authorization' => 'Bearer not-a-real-token',
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ])->assertUnauthorized();

    Log::shouldHaveReceived('info')->once()->withArgs(
        fn (string $message, array $context): bool => $context['has_token'] === true && $context['status'] === 401,
    );
});

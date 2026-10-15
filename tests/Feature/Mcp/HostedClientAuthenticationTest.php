<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use Illuminate\Testing\TestResponse;

const CLAUDE_IP = '160.79.104.10';
const CHATGPT_IP = '203.0.113.5';
const CUSTOM_CLIENT_IP = '198.51.100.7';

beforeEach(function () {
    fakeChatGptEgressRanges(['203.0.113.0/24']);

    $result = createApiTestToken();
    subscribeAccount($result['user']->account);

    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->registeredTools = count((new ReflectionClass(TryPostServer::class))->getDefaultProperties()['tools']);
});

/**
 * @param  array<string, mixed>  $params
 */
function hostedMcpRequest(object $test, string $method, array $params = [], string $ip = CUSTOM_CLIENT_IP, ?string $token = null, string $userAgent = 'test-client'): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(array_filter([
            'Authorization' => $token ? "Bearer {$token}" : null,
            'Accept' => 'application/json, text/event-stream',
            'User-Agent' => $userAgent,
        ]))
        ->postJson(route('mcp.trypost'), [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => $method,
            'params' => (object) $params,
        ]);
}

/**
 * @return array<string, mixed>
 */
function initializeParams(): array
{
    return [
        'protocolVersion' => '2025-03-26',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
    ];
}

function workspaceToolCall(): array
{
    return ['name' => 'get-workspace-tool', 'arguments' => (object) []];
}

function assertOAuthChallenge(TestResponse $response): void
{
    $response->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))
        ->toContain('resource_metadata="'.route('mcp.oauth.protected-resource.nested', ['path' => 'mcp/trypost']).'"');
}

describe('custom MCP clients keep the plain OAuth flow', function () {
    test('every request without a token is refused with a 401 challenge', function (string $method, array $params) {
        assertOAuthChallenge(hostedMcpRequest($this, $method, $params));
    })->with([
        'initialize' => ['initialize', initializeParams()],
        'tools/list' => ['tools/list', []],
        'tools/call' => ['tools/call', workspaceToolCall()],
    ]);

    test('a client that only claims to be Claude in its clientInfo is still refused', function () {
        assertOAuthChallenge(hostedMcpRequest($this, 'initialize', [...initializeParams(), 'clientInfo' => ['name' => 'claude-ai', 'version' => '1.0']]));
    });
});

describe('Claude connects without a token and signs in from the conversation', function () {
    test('initialize and tools/list answer without a token', function () {
        hostedMcpRequest($this, 'initialize', initializeParams(), CLAUDE_IP)->assertOk()->assertJsonPath('result.serverInfo.name', 'TryPost');

        expect(hostedMcpRequest($this, 'tools/list', [], CLAUDE_IP)->assertOk()->json('result.tools'))->toHaveCount($this->registeredTools);
    });

    test('a tool call without a token is refused with the 401 Claude signs in from', function () {
        assertOAuthChallenge(hostedMcpRequest($this, 'tools/call', workspaceToolCall(), CLAUDE_IP));
    });

    test('a request with an invalid token is refused with a 401, even to connect', function () {
        assertOAuthChallenge(hostedMcpRequest($this, 'initialize', initializeParams(), CLAUDE_IP, token: 'not-a-real-token'));
        assertOAuthChallenge(hostedMcpRequest($this, 'tools/list', [], CLAUDE_IP, token: 'not-a-real-token'));
    });

    test('a client outside the published range is not taken for Claude by its user agent', function () {
        assertOAuthChallenge(hostedMcpRequest($this, 'tools/list', [], userAgent: 'Claude-User'));
    });
});

describe('ChatGPT connects without a token and signs in from the conversation', function () {
    test('initialize and tools/list answer without a token', function () {
        hostedMcpRequest($this, 'initialize', initializeParams(), CHATGPT_IP)->assertOk();

        expect(hostedMcpRequest($this, 'tools/list', [], CHATGPT_IP)->assertOk()->json('result.tools'))->toHaveCount($this->registeredTools);
    });

    test('a tool call without a token answers the sign-in prompt ChatGPT shows', function (?string $token) {
        $response = hostedMcpRequest($this, 'tools/call', workspaceToolCall(), CHATGPT_IP, token: $token)->assertOk();

        $challenge = $response->json('result._meta')['mcp/www_authenticate'][0];

        expect($response->json('id'))->toBe(7)
            ->and($response->json('result.isError'))->toBeTrue()
            ->and($response->json('result.content.0.text'))->toBe('Sign in to TryPost to continue.')
            ->and($challenge)->toStartWith('Bearer ')
            ->toContain('resource_metadata="'.route('mcp.oauth.protected-resource.nested', ['path' => 'mcp/trypost']).'"')
            ->toContain('error="insufficient_scope"')
            ->toContain('error_description="Sign in to TryPost to continue."');
    })->with([
        'no token' => [null],
        'invalid token' => ['not-a-real-token'],
    ]);

    test('a client outside the published ranges is not taken for ChatGPT by its user agent', function () {
        assertOAuthChallenge(hostedMcpRequest($this, 'initialize', initializeParams(), userAgent: 'openai-mcp/1.0.0'));
        assertOAuthChallenge(hostedMcpRequest($this, 'tools/call', workspaceToolCall(), userAgent: 'openai-mcp/1.0.0'));
    });

    test('ChatGPT is treated like any client when OpenAI\'s ranges cannot be read', function () {
        fakeChatGptEgressRanges(null);

        assertOAuthChallenge(hostedMcpRequest($this, 'initialize', initializeParams(), CHATGPT_IP));
    });
});

describe('a signed-in user gets the same answers from every client', function () {
    test('a tool call runs normally', function (string $ip) {
        $token = mcpBearerToken($this->user, $this->workspace)['plain_token'];

        hostedMcpRequest($this, 'tools/call', workspaceToolCall(), $ip, $token)
            ->assertOk()
            ->assertJsonPath('result.isError', false);
    })->with([
        'custom client' => [CUSTOM_CLIENT_IP],
        'Claude' => [CLAUDE_IP],
        'ChatGPT' => [CHATGPT_IP],
    ]);

    test('an account without a subscription still gets the 402', function (string $ip) {
        config(['trypost.self_hosted' => false, 'trypost.billing.require_card_for_trial' => true]);
        $unsubscribed = createApiTestToken();
        $token = mcpBearerToken($unsubscribed['user'], $unsubscribed['workspace'])['plain_token'];

        hostedMcpRequest($this, 'tools/call', workspaceToolCall(), $ip, $token)->assertPaymentRequired();
    })->with([
        'custom client' => [CUSTOM_CLIENT_IP],
        'Claude' => [CLAUDE_IP],
        'ChatGPT' => [CHATGPT_IP],
    ]);

    test('every listed tool declares the OAuth security scheme', function () {
        $token = mcpBearerToken($this->user, $this->workspace)['plain_token'];

        $tools = hostedMcpRequest($this, 'tools/list', [], token: $token)->assertOk()->json('result.tools');

        expect($tools)->toHaveCount($this->registeredTools)
            ->each(fn ($tool) => $tool->securitySchemes->toBe([['type' => 'oauth2', 'scopes' => ['mcp:use']]]));
    });
});

<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const CHATGPT_SIGN_IN_CLAUDE_IP = '160.79.104.10';
const CHATGPT_SIGN_IN_CHATGPT_IP = '203.0.113.5';
const CHATGPT_SIGN_IN_OTHER_IP = '198.51.100.7';

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
function chatGptSignInRequest(object $test, string $method, array $params = [], string $ip = CHATGPT_SIGN_IN_OTHER_IP, ?string $token = null, string $userAgent = 'test-client', bool $notification = false): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withHeaders(array_filter([
            'Authorization' => $token ? "Bearer {$token}" : null,
            'Accept' => 'application/json, text/event-stream',
            'User-Agent' => $userAgent,
        ]))
        ->postJson(route('mcp.trypost'), array_filter([
            'jsonrpc' => '2.0',
            'id' => $notification ? null : 7,
            'method' => $method,
            'params' => (object) $params,
        ], fn (mixed $value): bool => $value !== null));
}

/**
 * @return array<string, mixed>
 */
function chatGptSignInInitializeParams(): array
{
    return [
        'protocolVersion' => '2025-03-26',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 'Pest', 'version' => '1.0'],
    ];
}

/**
 * @return array<string, mixed>
 */
function chatGptSignInToolCall(): array
{
    return ['name' => 'get-workspace-tool', 'arguments' => (object) []];
}

function chatGptSignInAssertOAuthChallenge(TestResponse $response): void
{
    $response->assertUnauthorized();

    expect($response->headers->get('WWW-Authenticate'))
        ->toContain('resource_metadata="'.route('mcp.oauth.protected-resource.nested', ['path' => 'mcp/trypost']).'"');
}

function chatGptSignInAssertChatGptChallenge(TestResponse $response): void
{
    $response->assertOk();

    $challenge = $response->json('result._meta')['mcp/www_authenticate'][0];

    expect($response->json('id'))->toBe(7)
        ->and($response->json('result.isError'))->toBeTrue()
        ->and($response->json('result.content.0.text'))->toBe('Sign in to TryPost to continue.')
        ->and($challenge)->toStartWith('Bearer ')
        ->toContain('resource_metadata="'.route('mcp.oauth.protected-resource.nested', ['path' => 'mcp/trypost']).'"')
        ->toContain('error="insufficient_scope"')
        ->toContain('error_description="Sign in to TryPost to continue."');
}

function chatGptSignInRangeReads(): int
{
    return Http::recorded(fn (HttpClientRequest $request): bool => $request->url() === config('trypost.mcp_chatgpt_ip_ranges_url'))->count();
}

describe('custom MCP clients keep the plain OAuth flow', function () {
    test('every request without a token is refused with a 401 challenge', function (string $method, array $params) {
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, $method, $params));
    })->with([
        'initialize' => ['initialize', chatGptSignInInitializeParams()],
        'ping' => ['ping', []],
        'tools/list' => ['tools/list', []],
        'tools/call' => ['tools/call', chatGptSignInToolCall()],
    ]);

    test('a client that only claims to be ChatGPT in its clientInfo is still refused', function () {
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, 'initialize', [...chatGptSignInInitializeParams(), 'clientInfo' => ['name' => 'openai-mcp', 'version' => '1.0']]));
    });

    test('a client is not taken for ChatGPT by its user agent', function (string $userAgent) {
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, 'initialize', chatGptSignInInitializeParams(), userAgent: $userAgent));
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, 'tools/call', chatGptSignInToolCall(), userAgent: $userAgent));
    })->with([
        'openai-mcp' => ['openai-mcp/1.0.0'],
        'ChatGPT-User' => ['Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)'],
    ]);
});

describe('Claude keeps the plain OAuth flow', function () {
    test('every request without a token is refused with the 401 Claude signs in from', function (string $method, array $params) {
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, $method, $params, CHATGPT_SIGN_IN_CLAUDE_IP));
    })->with([
        'initialize' => ['initialize', chatGptSignInInitializeParams()],
        'ping' => ['ping', []],
        'tools/list' => ['tools/list', []],
        'tools/call' => ['tools/call', chatGptSignInToolCall()],
    ]);
});

describe('ChatGPT connects without a token and signs in from the conversation', function () {
    test('connecting and listing tools answer without a token, with the OAuth security scheme', function () {
        chatGptSignInRequest($this, 'initialize', chatGptSignInInitializeParams(), CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk()->assertJsonPath('result.serverInfo.name', 'TryPost');
        chatGptSignInRequest($this, 'notifications/initialized', ip: CHATGPT_SIGN_IN_CHATGPT_IP, notification: true)->assertAccepted();
        chatGptSignInRequest($this, 'ping', ip: CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk();

        $tools = chatGptSignInRequest($this, 'tools/list', [], CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk()->json('result.tools');

        expect($tools)->toHaveCount($this->registeredTools)
            ->each(fn ($tool) => $tool->securitySchemes->toBe([['type' => 'oauth2', 'scopes' => ['mcp:use']]]));
    });

    test('a tool call without a valid token answers the sign-in prompt ChatGPT shows', function (?string $token) {
        chatGptSignInAssertChatGptChallenge(chatGptSignInRequest($this, 'tools/call', chatGptSignInToolCall(), CHATGPT_SIGN_IN_CHATGPT_IP, token: $token));
    })->with([
        'no token' => [null],
        'invalid token' => ['not-a-real-token'],
    ]);

    test('anything but connecting and listing tools is refused without a token', function (string $method, array $params) {
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, $method, $params, CHATGPT_SIGN_IN_CHATGPT_IP));
    })->with([
        'resources/list' => ['resources/list', []],
        'prompts/list' => ['prompts/list', []],
    ]);

    test('a request with an invalid token is refused with a 401, even to connect', function () {
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, 'initialize', chatGptSignInInitializeParams(), CHATGPT_SIGN_IN_CHATGPT_IP, token: 'not-a-real-token'));
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, 'tools/list', [], CHATGPT_SIGN_IN_CHATGPT_IP, token: 'not-a-real-token'));
    });

    test('a tool call with an expired token answers the sign-in prompt', function () {
        $issued = mcpBearerToken($this->user, $this->workspace);
        $issued['token']->forceFill(['expires_at' => now()->subMinute()])->save();

        chatGptSignInAssertChatGptChallenge(chatGptSignInRequest($this, 'tools/call', chatGptSignInToolCall(), CHATGPT_SIGN_IN_CHATGPT_IP, $issued['plain_token']));
    });
});

describe('OpenAI\'s published ranges', function () {
    test('are read once and kept for an hour', function () {
        chatGptSignInRequest($this, 'tools/list', [], CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk();
        chatGptSignInRequest($this, 'tools/list', [], CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk();

        $this->travel(59)->minutes();
        chatGptSignInRequest($this, 'tools/list', [], CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk();

        expect(chatGptSignInRangeReads())->toBe(1);

        $this->travel(2)->minutes();
        chatGptSignInRequest($this, 'tools/list', [], CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk();

        expect(chatGptSignInRangeReads())->toBe(2);
    });

    test('are not read for a request that carries a token', function () {
        $token = mcpBearerToken($this->user, $this->workspace)['plain_token'];

        chatGptSignInRequest($this, 'tools/list', [], CHATGPT_SIGN_IN_CHATGPT_IP, $token)->assertOk();

        expect(chatGptSignInRangeReads())->toBe(0);
    });

    test('a failed read treats ChatGPT like any client and is retried after a minute', function () {
        fakeChatGptEgressRanges(null);

        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, 'initialize', chatGptSignInInitializeParams(), CHATGPT_SIGN_IN_CHATGPT_IP));

        fakeChatGptEgressRanges(['203.0.113.0/24']);
        $this->travel(2)->minutes();

        chatGptSignInRequest($this, 'initialize', chatGptSignInInitializeParams(), CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk();
    });

    test('only valid IPv4 ranges of /8 or narrower are kept', function () {
        fakeChatGptEgressRanges(['0.0.0.0/0', '10.0.0.0/4', 'not-a-range', '999.1.1.1/24', ['203.0.113.0/24'], '203.0.113.0/24']);

        chatGptSignInRequest($this, 'initialize', chatGptSignInInitializeParams(), CHATGPT_SIGN_IN_CHATGPT_IP)->assertOk();
        chatGptSignInAssertOAuthChallenge(chatGptSignInRequest($this, 'initialize', chatGptSignInInitializeParams(), CHATGPT_SIGN_IN_OTHER_IP));
    });
});

describe('a signed-in user gets the same answers from every client', function () {
    test('a tool call runs normally', function (string $ip) {
        $token = mcpBearerToken($this->user, $this->workspace)['plain_token'];

        chatGptSignInRequest($this, 'tools/call', chatGptSignInToolCall(), $ip, $token)
            ->assertOk()
            ->assertJsonPath('result.isError', false);
    })->with([
        'custom client' => [CHATGPT_SIGN_IN_OTHER_IP],
        'Claude' => [CHATGPT_SIGN_IN_CLAUDE_IP],
        'ChatGPT' => [CHATGPT_SIGN_IN_CHATGPT_IP],
    ]);

    test('an account without a subscription still gets the 402', function (string $ip) {
        config(['trypost.self_hosted' => false, 'trypost.billing.require_card_for_trial' => true]);
        $unsubscribed = createApiTestToken();
        $token = mcpBearerToken($unsubscribed['user'], $unsubscribed['workspace'])['plain_token'];

        chatGptSignInRequest($this, 'tools/call', chatGptSignInToolCall(), $ip, $token)->assertPaymentRequired();
    })->with([
        'custom client' => [CHATGPT_SIGN_IN_OTHER_IP],
        'Claude' => [CHATGPT_SIGN_IN_CLAUDE_IP],
        'ChatGPT' => [CHATGPT_SIGN_IN_CHATGPT_IP],
    ]);

    test('every listed tool declares the OAuth security scheme', function () {
        $token = mcpBearerToken($this->user, $this->workspace)['plain_token'];

        $tools = chatGptSignInRequest($this, 'tools/list', [], token: $token)->assertOk()->json('result.tools');

        expect($tools)->toHaveCount($this->registeredTools)
            ->each(fn ($tool) => $tool->securitySchemes->toBe([['type' => 'oauth2', 'scopes' => ['mcp:use']]]));
    });
});

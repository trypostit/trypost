<?php

declare(strict_types=1);

use App\Enums\Mcp\Method;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

const MCP_RATE_LIMIT_CHATGPT_IP = '203.0.113.5';

beforeEach(function () {
    fakeChatGptEgressRanges(['203.0.113.0/24']);

    $result = createApiTestToken();
    subscribeAccount($result['user']->account);

    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->mcpToken = mcpBearerToken($this->user, $this->workspace, clientName: 'Claude')['plain_token'];
});

/**
 * @param  array<string, mixed>  $params
 */
function mcpRateLimitRequest(object $test, ?string $token, string $method, array $params = [], ?string $ip = null): TestResponse
{
    return $test->withServerVariables(array_filter(['REMOTE_ADDR' => $ip]))
        ->withHeaders(array_filter([
            'Authorization' => $token ? "Bearer {$token}" : null,
            'Accept' => 'application/json, text/event-stream',
        ]))
        ->postJson(route('mcp.trypost'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ]);
}

function mcpRequestsPerMinute(User $user): int
{
    $request = Request::create(route('mcp.trypost'), 'POST');
    $request->setUserResolver(fn (): User => $user);

    return RateLimiter::limiter('mcp')($request)->maxAttempts;
}

/**
 * @return array{name: string, arguments: object}
 */
function mcpRateLimitGetWorkspace(): array
{
    return ['name' => 'get-workspace-tool', 'arguments' => (object) []];
}

function mcpRateLimitSpend(object $test, string $token, int $requests): void
{
    foreach (range(1, $requests) as $ignored) {
        mcpRateLimitRequest($test, $token, Method::Ping->value)->assertOk();
    }
}

test('the request after the limit is refused with 429 and Retry-After', function () {
    mcpRateLimitSpend($this, $this->mcpToken, mcpRequestsPerMinute($this->user));

    $response = mcpRateLimitRequest($this, $this->mcpToken, Method::ToolsCall->value, mcpRateLimitGetWorkspace());

    $response->assertTooManyRequests();

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        ->and($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

test('the limit lifts when the minute is over', function () {
    mcpRateLimitSpend($this, $this->mcpToken, mcpRequestsPerMinute($this->user));
    mcpRateLimitRequest($this, $this->mcpToken, Method::Ping->value)->assertTooManyRequests();

    $this->travel(61)->seconds();

    mcpRateLimitRequest($this, $this->mcpToken, Method::ToolsCall->value, mcpRateLimitGetWorkspace())->assertOk();
});

test('a repeated tool call counts each time it is answered', function () {
    $limit = mcpRequestsPerMinute($this->user);

    foreach (range(1, $limit) as $ignored) {
        mcpRateLimitRequest($this, $this->mcpToken, Method::ToolsCall->value, mcpRateLimitGetWorkspace())->assertOk();
    }

    mcpRateLimitRequest($this, $this->mcpToken, Method::ToolsCall->value, mcpRateLimitGetWorkspace())->assertTooManyRequests();
});

test('every token of a workspace draws on the same limit', function () {
    $secondToken = mcpBearerToken($this->user, $this->workspace, clientName: 'ChatGPT')['plain_token'];

    mcpRateLimitSpend($this, $this->mcpToken, mcpRequestsPerMinute($this->user));

    mcpRateLimitRequest($this, $secondToken, Method::Ping->value)->assertTooManyRequests();
});

test('one workspace reaching its limit leaves another workspace alone', function () {
    $other = createApiTestToken();
    subscribeAccount($other['user']->account);
    $otherToken = mcpBearerToken($other['user'], $other['workspace'], clientName: 'Claude')['plain_token'];

    mcpRateLimitSpend($this, $this->mcpToken, mcpRequestsPerMinute($this->user));
    mcpRateLimitRequest($this, $this->mcpToken, Method::Ping->value)->assertTooManyRequests();

    mcpRateLimitRequest($this, $otherToken, Method::ToolsCall->value, mcpRateLimitGetWorkspace())->assertOk();
});

test('requests refused before the workspace is known do not count', function () {
    foreach (range(1, mcpRequestsPerMinute($this->user) + 1) as $ignored) {
        mcpRateLimitRequest($this, 'not-a-token', Method::Ping->value)->assertUnauthorized();
    }

    mcpRateLimitRequest($this, $this->mcpToken, Method::Ping->value)->assertOk();
});

test('ChatGPT reading the tool list before sign-in is not limited', function () {
    foreach (range(1, mcpRequestsPerMinute($this->user) + 1) as $ignored) {
        mcpRateLimitRequest($this, null, Method::ToolsList->value, ip: MCP_RATE_LIMIT_CHATGPT_IP)->assertOk();
    }
});

test('local is unlimited', function () {
    $limit = mcpRequestsPerMinute($this->user);

    app()->detectEnvironment(fn () => 'local');

    mcpRateLimitSpend($this, $this->mcpToken, $limit + 1);
});

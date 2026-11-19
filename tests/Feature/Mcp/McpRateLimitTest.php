<?php

declare(strict_types=1);

use App\Enums\Mcp\Method;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

const MCP_RATE_LIMIT_CHATGPT_IP = '203.0.113.5';

beforeEach(function () {
    app()->detectEnvironment(fn (): string => 'production');
    fakeChatGptEgressRanges(['203.0.113.0/24']);

    config([
        'trypost.mcp_requests_per_minute' => 3,
        'trypost.mcp_requests_per_hour' => 5,
    ]);
});

/**
 * A subscribed user and their workspace.
 *
 * @return array{user: User, workspace: Workspace}
 */
function mcpRateLimitWorkspace(): array
{
    ['user' => $user, 'workspace' => $workspace] = createApiTestToken();
    subscribeAccount($user->account);

    return ['user' => $user, 'workspace' => $workspace];
}

function mcpRateLimitToken(User $user, Workspace $workspace): string
{
    return mcpBearerToken($user, $workspace, clientName: 'Claude')['plain_token'];
}

/**
 * @param  array<string, mixed>  $params
 */
function mcpRateLimitCall(TestCase $test, ?string $token, Method $method = Method::Ping, array $params = [], ?string $ip = null): TestResponse
{
    auth()->forgetGuards();

    return $test->withServerVariables(Arr::whereNotNull(['REMOTE_ADDR' => $ip]))
        ->withHeaders(Arr::whereNotNull([
            'Authorization' => transform($token, fn (string $token): string => "Bearer {$token}"),
            'Accept' => 'application/json, text/event-stream',
        ]))
        ->postJson(route('mcp.trypost'), [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method->value,
            'params' => (object) $params,
        ]);
}

function mcpRateLimitSpend(TestCase $test, string $token, int $requests): void
{
    Collection::times($requests, fn () => mcpRateLimitCall($test, $token)->assertOk());
}

test('a tool call past the minute limit is refused with 429 and Retry-After', function () {
    ['user' => $user, 'workspace' => $workspace] = mcpRateLimitWorkspace();
    $token = mcpRateLimitToken($user, $workspace);

    mcpRateLimitSpend($this, $token, 3);

    $response = mcpRateLimitCall($this, $token, Method::ToolsCall, ['name' => 'get-workspace-tool', 'arguments' => (object) []]);

    $response->assertTooManyRequests();

    expect((int) $response->headers->get('Retry-After'))->toBeBetween(1, 60);
});

test('the minute limit lifts once the minute is over', function () {
    ['user' => $user, 'workspace' => $workspace] = mcpRateLimitWorkspace();
    $token = mcpRateLimitToken($user, $workspace);

    mcpRateLimitSpend($this, $token, 3);
    mcpRateLimitCall($this, $token)->assertTooManyRequests();

    $this->travel(61)->seconds();

    mcpRateLimitCall($this, $token)->assertOk();
});

test('the hour limit holds across minutes until the hour is over', function () {
    ['user' => $user, 'workspace' => $workspace] = mcpRateLimitWorkspace();
    $token = mcpRateLimitToken($user, $workspace);

    mcpRateLimitSpend($this, $token, 3);
    $this->travel(61)->seconds();
    mcpRateLimitSpend($this, $token, 2);
    $this->travel(61)->seconds();

    mcpRateLimitCall($this, $token)->assertTooManyRequests();

    $this->travel(1)->hours();

    mcpRateLimitCall($this, $token)->assertOk();
});

test('a teammate in the same workspace keeps their own limit', function () {
    ['user' => $user, 'workspace' => $workspace] = mcpRateLimitWorkspace();

    mcpRateLimitSpend($this, mcpRateLimitToken($user, $workspace), 3);

    mcpRateLimitCall($this, mcpRateLimitToken(workspaceMember($workspace), $workspace))->assertOk();
});

test('requests refused at sign-in do not count', function () {
    ['user' => $user, 'workspace' => $workspace] = mcpRateLimitWorkspace();
    $token = mcpRateLimitToken($user, $workspace);

    Collection::times(4, fn () => mcpRateLimitCall($this, 'not-a-token')->assertUnauthorized());

    mcpRateLimitCall($this, $token)->assertOk();
});

test('requests refused for the workspace do not count', function () {
    ['workspace' => $workspace] = mcpRateLimitWorkspace();
    $outsider = workspaceOutsider($workspace);
    $token = mcpRateLimitToken($outsider, $workspace);

    Collection::times(4, fn () => mcpRateLimitCall($this, $token)->assertForbidden());

    $workspace->members()->attach($outsider->id, membershipPivot('member'));

    mcpRateLimitCall($this, $token)->assertOk();
});

test('ChatGPT with a token is limited by its token like any client', function () {
    ['user' => $user, 'workspace' => $workspace] = mcpRateLimitWorkspace();
    $token = mcpRateLimitToken($user, $workspace);

    Collection::times(3, fn () => mcpRateLimitCall($this, $token, ip: MCP_RATE_LIMIT_CHATGPT_IP)->assertOk());

    mcpRateLimitCall($this, $token, Method::ToolsCall, ['name' => 'get-workspace-tool', 'arguments' => (object) []], MCP_RATE_LIMIT_CHATGPT_IP)
        ->assertTooManyRequests();
});

test('ChatGPT listing the tools before sign-in is not limited', function () {
    Collection::times(4, fn () => mcpRateLimitCall($this, null, Method::ToolsList, ip: MCP_RATE_LIMIT_CHATGPT_IP)->assertOk());
});

test('outside production is not limited', function () {
    ['user' => $user, 'workspace' => $workspace] = mcpRateLimitWorkspace();
    $token = mcpRateLimitToken($user, $workspace);

    app()->detectEnvironment(fn (): string => 'staging');

    mcpRateLimitSpend($this, $token, 4);
});

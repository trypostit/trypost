<?php

declare(strict_types=1);

use App\Enums\Mcp\Method;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

const MCP_RATE_LIMIT_CHATGPT_IP = '203.0.113.5';

beforeEach(function () {
    fakeChatGptEgressRanges(['203.0.113.0/24']);

    config([
        'trypost.mcp_requests_per_minute' => 3,
        'trypost.mcp_requests_per_hour' => 5,
    ]);
});

/**
 * An MCP connection: one AI client, for a subscribed user, in their workspace.
 */
function mcpRateLimitConnection(): AccessToken
{
    ['user' => $user, 'workspace' => $workspace] = createApiTestToken();
    subscribeAccount($user->account);

    return mcpBearerToken($user, $workspace, clientName: 'Claude')['token'];
}

/**
 * A new token of the connection, as a refresh gives one, or of another
 * connection when the user, workspace or AI client differs.
 */
function mcpRateLimitToken(AccessToken $connection, ?User $user = null, ?Workspace $workspace = null, ?string $clientId = null): string
{
    $token = mcpBearerToken($user ?? $connection->user, $workspace ?? $connection->workspace);
    $token['token']->forceFill(['client_id' => $clientId ?? $connection->client_id])->saveQuietly();

    return $token['plain_token'];
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
    $connection = mcpRateLimitConnection();
    $token = mcpRateLimitToken($connection);

    mcpRateLimitSpend($this, $token, 3);

    $response = mcpRateLimitCall($this, $token, Method::ToolsCall, ['name' => 'get-workspace-tool', 'arguments' => (object) []]);

    $response->assertTooManyRequests();

    expect((int) $response->headers->get('Retry-After'))->toBeBetween(1, 60);
});

test('the minute limit lifts once the minute is over', function () {
    $connection = mcpRateLimitConnection();
    $token = mcpRateLimitToken($connection);

    mcpRateLimitSpend($this, $token, 3);
    mcpRateLimitCall($this, $token)->assertTooManyRequests();

    $this->travel(61)->seconds();

    mcpRateLimitCall($this, $token)->assertOk();
});

test('the hour limit holds across minutes until the hour is over', function () {
    $connection = mcpRateLimitConnection();
    $token = mcpRateLimitToken($connection);

    mcpRateLimitSpend($this, $token, 3);
    $this->travel(61)->seconds();
    mcpRateLimitSpend($this, $token, 2);
    $this->travel(61)->seconds();

    mcpRateLimitCall($this, $token)->assertTooManyRequests();

    $this->travel(1)->hours();

    mcpRateLimitCall($this, $token)->assertOk();
});

test('a refreshed token keeps the count of its connection', function () {
    $connection = mcpRateLimitConnection();

    mcpRateLimitSpend($this, mcpRateLimitToken($connection), 3);

    mcpRateLimitCall($this, mcpRateLimitToken($connection))
        ->assertTooManyRequests();
});

test('another AI client of the same user keeps its own limit', function () {
    $connection = mcpRateLimitConnection();

    mcpRateLimitSpend($this, mcpRateLimitToken($connection), 3);

    mcpRateLimitCall($this, mcpRateLimitToken($connection, clientId: mcpOauthClient('ChatGPT')))
        ->assertOk();
});

test('a teammate on the same AI client keeps their own limit', function () {
    $connection = mcpRateLimitConnection();
    $teammate = workspaceMember($connection->workspace);

    mcpRateLimitSpend($this, mcpRateLimitToken($connection), 3);

    mcpRateLimitCall($this, mcpRateLimitToken($connection, user: $teammate))
        ->assertOk();
});

test('the same AI client in another workspace keeps its own limit', function () {
    $connection = mcpRateLimitConnection();
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $connection->user->account_id,
        'user_id' => $connection->user->id,
    ]);
    $otherWorkspace->members()->attach($connection->user->id, membershipPivot('admin'));

    mcpRateLimitSpend($this, mcpRateLimitToken($connection), 3);

    mcpRateLimitCall($this, mcpRateLimitToken($connection, workspace: $otherWorkspace))
        ->assertOk();
});

test('requests refused at sign-in do not count', function () {
    $connection = mcpRateLimitConnection();

    Collection::times(4, fn () => mcpRateLimitCall($this, 'not-a-token')->assertUnauthorized());

    mcpRateLimitCall($this, mcpRateLimitToken($connection))
        ->assertOk();
});

test('ChatGPT listing the tools before sign-in is not limited', function () {
    Collection::times(4, fn () => mcpRateLimitCall($this, null, Method::ToolsList, ip: MCP_RATE_LIMIT_CHATGPT_IP)->assertOk());
});

test('local is not limited', function () {
    $connection = mcpRateLimitConnection();

    app()->detectEnvironment(fn (): string => 'local');

    mcpRateLimitSpend($this, mcpRateLimitToken($connection), 4);
});

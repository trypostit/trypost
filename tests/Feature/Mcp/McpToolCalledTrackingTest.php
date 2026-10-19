<?php

declare(strict_types=1);

use App\Enums\AccessToken\McpClient;
use App\Enums\PostHog\McpEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Queue::fake([CaptureAccountEvent::class]);

    $result = createApiTestToken();
    subscribeAccount($result['user']->account);

    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->apiToken = $result['plain_token'];

    $this->mcpToken = mcpBearerToken($this->user, $this->workspace, clientName: 'Claude')['plain_token'];
});

/**
 * @param  array<string, mixed>  $params
 */
function mcpToolCalledRequest(object $test, string $token, string $method, array $params = []): TestResponse
{
    return $test->withHeaders([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => (object) $params,
    ]);
}

/**
 * @return array{name: string, arguments: object}
 */
function mcpToolCalledGetWorkspace(): array
{
    return ['name' => 'get-workspace-tool', 'arguments' => (object) []];
}

test('first tool call of the day is tracked with tool and client', function () {
    mcpToolCalledRequest($this, $this->mcpToken, 'tools/call', mcpToolCalledGetWorkspace())->assertOk();

    Queue::assertPushedOn('posthog', CaptureAccountEvent::class, fn (CaptureAccountEvent $job) => $job->event === McpEvent::ToolCalled
        && $job->distinctId === (string) $this->user->id
        && $job->accountId === (string) $this->workspace->account_id
        && $job->properties === [
            'tool' => 'get-workspace-tool',
            'client' => McpClient::Claude->value,
            'workspace_id' => (string) $this->workspace->id,
        ]);
});

test('second call the same day is not tracked', function () {
    mcpToolCalledRequest($this, $this->mcpToken, 'tools/call', mcpToolCalledGetWorkspace())->assertOk();
    mcpToolCalledRequest($this, $this->mcpToken, 'tools/call', ['name' => 'list-labels-tool', 'arguments' => (object) []])->assertOk();

    Queue::assertPushedOnce(CaptureAccountEvent::class);
});

test('the first call of the next day is tracked again', function () {
    $this->travelTo(now()->utc()->setTime(23, 0));
    mcpToolCalledRequest($this, $this->mcpToken, 'tools/call', mcpToolCalledGetWorkspace())->assertOk();

    $this->travelTo(now()->utc()->addDay()->setTime(1, 0));
    mcpToolCalledRequest($this, $this->mcpToken, 'tools/call', mcpToolCalledGetWorkspace())->assertOk();

    Queue::assertPushedTimes(CaptureAccountEvent::class, 2);
});

test('tools/list is not tracked', function () {
    mcpToolCalledRequest($this, $this->mcpToken, 'tools/list')->assertOk();

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

test('api key requests are not tracked, even with a tool call body', function () {
    $this->withToken($this->apiToken)->getJson(route('api.workspace.show'))->assertOk();
    $this->withToken($this->apiToken)->postJson(route('api.labels.store'), [
        'method' => 'tools/call',
        'params' => ['name' => 'x'],
    ]);

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

test('nothing is tracked or cached when PostHog is disabled', function () {
    config(['services.posthog.api_key' => null]);

    mcpToolCalledRequest($this, $this->mcpToken, 'tools/call', mcpToolCalledGetWorkspace())->assertOk();

    $today = now()->utc()->toDateString();

    Queue::assertNotPushed(CaptureAccountEvent::class);
    expect(Cache::has("posthog:mcp_tool_called:{$this->user->id}:{$today}"))->toBeFalse();
});

test('a tracking failure never fails the tool call', function () {
    Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));

    mcpToolCalledRequest($this, $this->mcpToken, 'tools/call', mcpToolCalledGetWorkspace())->assertOk();

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

test('a tool call still runs when the tracking dispatch fails', function () {
    failDispatchOf(CaptureAccountEvent::class);

    mcpToolCalledRequest($this, $this->mcpToken, 'tools/call', mcpToolCalledGetWorkspace())
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertSee((string) $this->workspace->id);

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $result = createApiTestToken();
    subscribeAccount($result['user']->account);

    $this->token = mcpBearerToken($result['user'], $result['workspace'])['plain_token'];
    $this->registeredTools = count((new ReflectionClass(TryPostServer::class))->getDefaultProperties()['tools']);
});

/**
 * @param  array<string, mixed>  $params
 */
function listMcpTools(mixed $test, array $params = []): TestResponse
{
    return $test->withHeaders([
        'Authorization' => "Bearer {$test->token}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) $params,
    ])->assertSuccessful();
}

test('tools/list returns every registered tool in one page, without a next cursor', function () {
    $response = listMcpTools($this);

    expect($response->json('result.tools'))->toHaveCount($this->registeredTools)
        ->and($response->json('result'))->not->toHaveKey('nextCursor');
});

test('a client asking for a bigger page still gets one page of up to 200 tools', function () {
    $response = listMcpTools($this, ['per_page' => 1000]);

    expect($response->json('result.tools'))->toHaveCount($this->registeredTools)
        ->and($response->json('result'))->not->toHaveKey('nextCursor');
});

test('a client asking for a smaller page can still follow the cursor', function () {
    $first = listMcpTools($this, ['per_page' => 5]);
    $second = listMcpTools($this, ['per_page' => 5, 'cursor' => $first->json('result.nextCursor')]);

    expect($first->json('result.tools'))->toHaveCount(5)
        ->and($first->json('result.nextCursor'))->toBeString()
        ->and($second->json('result.tools'))->toHaveCount(5)
        ->and(collect($second->json('result.tools'))->pluck('name'))
        ->not->toContain(...collect($first->json('result.tools'))->pluck('name'));
});

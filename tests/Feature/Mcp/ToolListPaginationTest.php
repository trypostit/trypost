<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;

beforeEach(function () {
    config(['trypost.self_hosted' => true]);

    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
});

test('tools/list returns every registered tool in one page, without a next cursor', function () {
    $issued = mcpBearerToken($this->user, $this->workspace);
    $registered = (new ReflectionClass(TryPostServer::class))->getDefaultProperties()['tools'];

    $response = $this->withHeaders([
        'Authorization' => "Bearer {$issued['plain_token']}",
        'Accept' => 'application/json, text/event-stream',
    ])->postJson(route('mcp.trypost'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => (object) [],
    ])->assertSuccessful();

    expect($response->json('result.tools'))->toHaveCount(count($registered))
        ->and($response->json('result'))->not->toHaveKey('nextCursor');
});

test('the server lists up to 200 tools per page, whatever page size a client asks for', function () {
    $defaults = (new ReflectionClass(TryPostServer::class))->getDefaultProperties();

    expect($defaults['defaultPaginationLength'])->toBe(200)
        ->and($defaults['maxPaginationLength'])->toBe(200);
});

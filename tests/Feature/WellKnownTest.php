<?php

declare(strict_types=1);

use App\Models\User;

test('a configured well-known file is served as plain text', function () {
    config()->set('trypost.well_known.openai-apps-challenge', 'challenge-token-123');

    $response = $this->get(route('well-known', 'openai-apps-challenge'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toBe('challenge-token-123');
});

test('a configured well-known file is served to signed-in users too', function () {
    config()->set('trypost.well_known.openai-apps-challenge', 'challenge-token-123');

    $response = $this->actingAs(User::factory()->create())->get(route('well-known', 'openai-apps-challenge'));

    $response->assertOk();

    expect($response->getContent())->toBe('challenge-token-123');
});

test('a configured well-known file without a value is not found', function (?string $value) {
    config()->set('trypost.well_known.openai-apps-challenge', $value);

    $this->get(route('well-known', 'openai-apps-challenge'))->assertNotFound();
})->with([
    'unset' => null,
    'empty' => '',
    'blank' => '   ',
]);

test('a well-known path outside the configured names is not found', function (string $path) {
    config()->set('trypost.well_known.openai-apps-challenge', 'challenge-token-123');

    $this->get($path)->assertNotFound();
})->with([
    'another file' => '/.well-known/security.txt',
    'different case' => '/.well-known/OPENAI-APPS-CHALLENGE',
    'nested path' => '/.well-known/openai-apps-challenge/extra',
    'extension' => '/.well-known/openai-apps-challenge.txt',
]);

test('a well-known file only answers GET and HEAD', function () {
    config()->set('trypost.well_known.openai-apps-challenge', 'challenge-token-123');

    $this->head(route('well-known', 'openai-apps-challenge'))->assertOk();
    $this->post(route('well-known', 'openai-apps-challenge'))->assertMethodNotAllowed();
});

test('the MCP OAuth discovery routes keep answering', function (string $name, array $parameters, string $key) {
    $this->get(route($name, $parameters))->assertOk()->assertJsonStructure([$key]);
})->with([
    'authorization server' => ['mcp.oauth.authorization-server', [], 'issuer'],
    'nested authorization server' => ['mcp.oauth.authorization-server.nested', ['path' => 'mcp/trypost'], 'issuer'],
    'protected resource' => ['mcp.oauth.protected-resource', [], 'resource'],
    'nested protected resource' => ['mcp.oauth.protected-resource.nested', ['path' => 'mcp/trypost'], 'resource'],
]);

<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

test('a configured well-known file is served as plain text, without a session', function () {
    config()->set('trypost.well_known.openai-apps-challenge', 'challenge-token-123');

    $this->get(route('well-known', 'openai-apps-challenge'))
        ->assertOk()
        ->assertContent('challenge-token-123')
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Set-Cookie');
});

test('a configured well-known file is served to signed-in users too', function () {
    config()->set('trypost.well_known.openai-apps-challenge', 'challenge-token-123');

    $this->actingAs(User::factory()->create())
        ->get(route('well-known', 'openai-apps-challenge'))
        ->assertOk()
        ->assertContent('challenge-token-123');
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

test('a well-known route registered after this one still answers', function () {
    Route::get('.well-known/security.txt', fn () => 'other')->name('test.well-known.security');
    Route::getRoutes()->refreshNameLookups();

    $this->get(route('test.well-known.security'))->assertOk()->assertContent('other');
});

test('the MCP OAuth discovery routes keep answering', function (string $name, array $parameters, string $key) {
    $this->get(route($name, $parameters))->assertOk()->assertJsonStructure([$key]);
})->with([
    'authorization server' => ['mcp.oauth.authorization-server', [], 'issuer'],
    'nested authorization server' => ['mcp.oauth.authorization-server.nested', ['path' => 'mcp/trypost'], 'issuer'],
    'protected resource' => ['mcp.oauth.protected-resource', [], 'resource'],
    'nested protected resource' => ['mcp.oauth.protected-resource.nested', ['path' => 'mcp/trypost'], 'resource'],
]);

test('no well-known file configured registers no route', function () {
    config()->set('trypost.well_known', []);
    Route::setRoutes(new RouteCollection);

    require base_path('routes/well-known.php');

    expect(Route::getRoutes()->getRoutes())->toBeEmpty();
});

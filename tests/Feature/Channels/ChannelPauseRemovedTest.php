<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use Illuminate\Support\Facades\Route;

test('the channel toggle routes no longer exist', function () {
    expect(Route::has('app.channels.toggle'))->toBeFalse()
        ->and(Route::has('api.social-accounts.toggle'))->toBeFalse();
});

test('the MCP server no longer registers a toggle tool', function () {
    $tools = (new ReflectionClass(TryPostServer::class))->getDefaultProperties()['tools'] ?? [];

    expect($tools)->not->toBeEmpty()
        ->and(collect($tools)->map(fn (string $tool): string => class_basename($tool)))
        ->not->toContain('ToggleSocialAccountTool');
});

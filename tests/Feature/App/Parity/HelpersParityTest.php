<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Workspace\GetWorkspaceTool;

test('the parity context reaches the same workspace through the api and the mcp server', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();

    $this->withHeaders(parityApi($token))
        ->getJson(route('api.workspace.show'))
        ->assertOk()
        ->assertJsonPath('id', $workspace->id);

    TryPostServer::actingAs($user)
        ->tool(GetWorkspaceTool::class, [])
        ->assertOk()
        ->assertSee($workspace->id);
});

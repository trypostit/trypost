<?php

declare(strict_types=1);

use App\Models\Workspace;

function createWorkspaceApiToken(array $overrides = []): array
{
    return createApiTestToken($overrides);
}

test('show current workspace', function () {
    $workspace = Workspace::factory()->create([
        'name' => 'Test Workspace',
    ]);

    $result = createWorkspaceApiToken(['workspace' => $workspace]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->getJson(
        route('api.workspace.show'),
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonPath('name', 'Test Workspace');
});

test('show workspace requires authentication', function () {
    $response = $this->getJson(
        route('api.workspace.show'),
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertUnauthorized();
});

test('workspace returns correct structure', function () {
    $result = createWorkspaceApiToken();

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->getJson(route('api.workspace.show'))
        ->assertOk()
        ->assertJsonStructure(['id', 'name', 'created_at', 'updated_at']);
});

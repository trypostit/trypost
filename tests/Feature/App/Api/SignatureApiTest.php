<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Models\WorkspaceSignature;

function createSignatureApiToken(array $overrides = []): array
{
    return createApiTestToken($overrides);
}

test('list signatures', function () {
    $result = createSignatureApiToken();

    WorkspaceSignature::factory()->count(3)->create([
        'workspace_id' => $result['workspace']->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->getJson(
        route('api.signatures.index'),
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonCount(3, 'data');
});

test('create signature', function () {
    $result = createSignatureApiToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->postJson(
        route('api.signatures.store'),
        [
            'name' => 'Marketing Tags',
            'content' => '#marketing #growth #saas',
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertCreated();
    $response->assertJsonPath('name', 'Marketing Tags');

    expect($result['workspace']->signatures()->count())->toBe(1);
});

test('create signature validation errors', function () {
    $result = createSignatureApiToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->postJson(
        route('api.signatures.store'),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name', 'content']);
});

test('update signature', function () {
    $result = createSignatureApiToken();

    $signature = WorkspaceSignature::factory()->create([
        'workspace_id' => $result['workspace']->id,
        'name' => 'Old Name',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->putJson(
        route('api.signatures.update', $signature),
        [
            'name' => 'Updated Name',
            'content' => '#updated #tags',
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonPath('name', 'Updated Name');
});

test('delete signature', function () {
    $result = createSignatureApiToken();

    $signature = WorkspaceSignature::factory()->create([
        'workspace_id' => $result['workspace']->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->deleteJson(
        route('api.signatures.destroy', $signature),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertNoContent();

    expect(WorkspaceSignature::find($signature->id))->toBeNull();
});

test('cannot access signatures from another workspace', function () {
    $result = createSignatureApiToken();

    $otherWorkspace = Workspace::factory()->create();
    $signature = WorkspaceSignature::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->putJson(
        route('api.signatures.update', $signature),
        [
            'name' => 'Hacked Name',
            'content' => '#hacked',
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertNotFound();
});

test('cannot update signature from another workspace', function () {
    $result = createSignatureApiToken();
    $otherWorkspace = Workspace::factory()->create();
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->putJson(route('api.signatures.update', $signature), [
            'name' => 'Hacked',
            'content' => '#hacked',
        ])
        ->assertNotFound();
});

test('update signature validation errors', function () {
    $result = createSignatureApiToken();
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => data_get($result, 'workspace')->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->putJson(route('api.signatures.update', $signature), [])
        ->assertUnprocessable();
});

test('list signatures returns correct structure', function () {
    $result = createSignatureApiToken();
    WorkspaceSignature::factory()->create(['workspace_id' => data_get($result, 'workspace')->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->getJson(route('api.signatures.index'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'name', 'content', 'created_at', 'updated_at']],
        ]);
});

test('cannot delete signature from another workspace', function () {
    $result = createSignatureApiToken();
    $otherWorkspace = Workspace::factory()->create();
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->deleteJson(route('api.signatures.destroy', $signature))
        ->assertNotFound();
});

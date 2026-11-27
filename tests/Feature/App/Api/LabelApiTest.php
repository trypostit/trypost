<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Models\WorkspaceLabel;

function createLabelApiToken(array $overrides = []): array
{
    return createApiTestToken($overrides);
}

test('list labels', function () {
    $result = createLabelApiToken();

    WorkspaceLabel::factory()->count(3)->create([
        'workspace_id' => $result['workspace']->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->getJson(
        route('api.labels.index'),
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonCount(3, 'data');
});

test('create label', function () {
    $result = createLabelApiToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->postJson(
        route('api.labels.store'),
        [
            'name' => 'Marketing',
            'color' => '#FF0000',
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertCreated();
    $response->assertJsonPath('name', 'Marketing');
    $response->assertJsonPath('color', '#FF0000');

    expect($result['workspace']->labels()->count())->toBe(1);
});

test('create label validation errors', function () {
    $result = createLabelApiToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->postJson(
        route('api.labels.store'),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name', 'color']);
});

test('create label validates color format', function () {
    $result = createLabelApiToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->postJson(
        route('api.labels.store'),
        [
            'name' => 'Bad Color',
            'color' => 'not-a-color',
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['color']);
});

test('update label', function () {
    $result = createLabelApiToken();

    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $result['workspace']->id,
        'name' => 'Old Name',
        'color' => '#000000',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->putJson(
        route('api.labels.update', $label),
        [
            'name' => 'Updated Name',
            'color' => '#FFFFFF',
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonPath('name', 'Updated Name');
    $response->assertJsonPath('color', '#FFFFFF');
});

test('delete label', function () {
    $result = createLabelApiToken();

    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $result['workspace']->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->deleteJson(
        route('api.labels.destroy', $label),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertNoContent();

    expect(WorkspaceLabel::find($label->id))->toBeNull();
});

test('cannot access labels from another workspace', function () {
    $result = createLabelApiToken();

    $otherWorkspace = Workspace::factory()->create();
    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->putJson(
        route('api.labels.update', $label),
        [
            'name' => 'Hacked Name',
            'color' => '#FF0000',
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertNotFound();
});

test('cannot update label from another workspace', function () {
    $result = createLabelApiToken();
    $otherWorkspace = Workspace::factory()->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->putJson(route('api.labels.update', $label), [
            'name' => 'Hacked',
            'color' => '#000000',
        ])
        ->assertNotFound();
});

test('update label validation errors', function () {
    $result = createLabelApiToken();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => data_get($result, 'workspace')->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->putJson(route('api.labels.update', $label), [])
        ->assertUnprocessable();
});

test('update label validates color format', function () {
    $result = createLabelApiToken();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => data_get($result, 'workspace')->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->putJson(route('api.labels.update', $label), [
            'name' => 'Test',
            'color' => 'invalid',
        ])
        ->assertUnprocessable();
});

test('list labels returns correct structure', function () {
    $result = createLabelApiToken();
    WorkspaceLabel::factory()->create(['workspace_id' => data_get($result, 'workspace')->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->getJson(route('api.labels.index'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'name', 'color', 'created_at', 'updated_at']],
        ]);
});

test('cannot delete label from another workspace', function () {
    $result = createLabelApiToken();
    $otherWorkspace = Workspace::factory()->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.data_get($result, 'plain_token')])
        ->deleteJson(route('api.labels.destroy', $label))
        ->assertNotFound();
});

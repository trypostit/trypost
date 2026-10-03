<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Label\CreateLabelTool;
use App\Mcp\Tools\Label\DeleteLabelTool;
use App\Mcp\Tools\Label\ListLabelsTool;
use App\Mcp\Tools\Label\UpdateLabelTool;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('list labels returns wrapped labels array with LabelResource shape', function () {
    WorkspaceLabel::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListLabelsTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('labels', 2, function (AssertableJson $label) {
                $label->hasAll(['id', 'name', 'color', 'created_at', 'updated_at'])
                    ->missing('workspace_id');
            });
        });
});

test('list labels only returns own workspace labels', function () {
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $otherWorkspace = Workspace::factory()->create();
    WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListLabelsTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('labels', 1)->etc();
        });
});

test('create label returns LabelResource shape', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateLabelTool::class, [
            'name' => 'Important',
            'color' => '#FF0000',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('name', 'Important')
                ->where('color', '#FF0000')
                ->missing('workspace_id')
                ->etc();
        });

    expect($this->workspace->labels()->count())->toBe(1);
});

test('create label validates required fields', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateLabelTool::class, []);

    $response->assertHasErrors();
});

test('create label validates color format', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateLabelTool::class, [
            'name' => 'Test',
            'color' => 'not-a-color',
        ]);

    $response->assertHasErrors();
});

test('update label returns updated LabelResource', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateLabelTool::class, [
            'label_id' => $label->id,
            'name' => 'Updated',
            'color' => '#00FF00',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('name', 'Updated')
                ->where('color', '#00FF00')
                ->etc();
        });
});

test('cannot update label from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateLabelTool::class, [
            'label_id' => $label->id,
            'name' => 'Hacked',
            'color' => '#000000',
        ]);

    $response->assertHasErrors(['Label not found.']);
});

test('delete label removes from db', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteLabelTool::class, ['label_id' => $label->id]);

    $response->assertOk()
        ->assertStructuredContent(['deleted' => true]);

    expect(WorkspaceLabel::find($label->id))->toBeNull();
});

test('cannot delete label from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteLabelTool::class, ['label_id' => $label->id]);

    $response->assertHasErrors(['Label not found.']);
});

test('update label validates required fields', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateLabelTool::class, []);

    $response->assertHasErrors();
});

test('delete label validates label_id required', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteLabelTool::class, []);

    $response->assertHasErrors();
});

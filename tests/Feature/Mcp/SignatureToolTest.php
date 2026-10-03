<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Signature\CreateSignatureTool;
use App\Mcp\Tools\Signature\DeleteSignatureTool;
use App\Mcp\Tools\Signature\ListSignaturesTool;
use App\Mcp\Tools\Signature\UpdateSignatureTool;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSignature;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('list signatures returns wrapped signatures array with SignatureResource shape', function () {
    WorkspaceSignature::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListSignaturesTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('signatures', 2, function (AssertableJson $sig) {
                $sig->hasAll(['id', 'name', 'content', 'created_at', 'updated_at'])
                    ->missing('workspace_id');
            });
        });
});

test('list signatures only returns own workspace signatures', function () {
    WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);
    $otherWorkspace = Workspace::factory()->create();
    WorkspaceSignature::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListSignaturesTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('signatures', 1)->etc();
        });
});

test('create signature returns SignatureResource shape', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateSignatureTool::class, [
            'name' => 'Marketing',
            'content' => '#marketing #social',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('name', 'Marketing')
                ->where('content', '#marketing #social')
                ->missing('workspace_id')
                ->etc();
        });

    expect($this->workspace->signatures()->count())->toBe(1);
});

test('create signature validates required fields', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreateSignatureTool::class, []);

    $response->assertHasErrors();
});

test('update signature returns updated SignatureResource', function () {
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateSignatureTool::class, [
            'signature_id' => $signature->id,
            'name' => 'Updated',
            'content' => '#updated',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('name', 'Updated')
                ->where('content', '#updated')
                ->etc();
        });
});

test('cannot update signature from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateSignatureTool::class, [
            'signature_id' => $signature->id,
            'name' => 'Hacked',
            'content' => '#hacked',
        ]);

    $response->assertHasErrors(['Signature not found.']);
});

test('delete signature removes from db', function () {
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteSignatureTool::class, ['signature_id' => $signature->id]);

    $response->assertOk()
        ->assertStructuredContent(['deleted' => true]);

    expect(WorkspaceSignature::find($signature->id))->toBeNull();
});

test('cannot delete signature from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteSignatureTool::class, ['signature_id' => $signature->id]);

    $response->assertHasErrors(['Signature not found.']);
});

test('update signature validates required fields', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateSignatureTool::class, []);

    $response->assertHasErrors();
});

test('delete signature validates signature_id required', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(DeleteSignatureTool::class, []);

    $response->assertHasErrors();
});

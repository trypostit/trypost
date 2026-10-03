<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Workspace\GetWorkspaceTool;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('get workspace returns sanitized WorkspaceResource shape', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(GetWorkspaceTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('id', $this->workspace->id)
                ->where('name', $this->workspace->name)
                ->hasAll(['created_at', 'updated_at'])
                ->missing('account_id')
                ->missing('user_id')
                ->missing('brand_color')
                ->missing('content_language');
        });
});

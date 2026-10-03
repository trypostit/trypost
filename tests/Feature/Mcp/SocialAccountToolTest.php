<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\SocialAccount\ListSocialAccountsTool;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('list returns wrapped social_accounts array with SocialAccountResource shape', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListSocialAccountsTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('social_accounts', 2, function (AssertableJson $account) {
                $account->hasAll(['id', 'platform', 'display_name', 'username', 'status', 'has_posting_schedule'])
                    ->missing('is_active')
                    ->missing('access_token')
                    ->missing('refresh_token')
                    ->missing('workspace_id');
            });
        });
});

test('list only returns own workspace accounts', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $otherWorkspace = Workspace::factory()->create();
    SocialAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'platform' => Platform::X,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListSocialAccountsTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('social_accounts', 1)->etc();
        });
});

test('list never exposes access_token or refresh_token', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'access_token' => 'secret-token-123',
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListSocialAccountsTool::class, []);

    $response->assertOk();
    $response->assertDontSee('secret-token-123');
});

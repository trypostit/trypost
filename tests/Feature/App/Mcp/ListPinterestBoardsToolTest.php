<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\SocialAccount\ListPinterestBoardsTool;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('lists pinterest boards as id and name', function () {
    $account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'access_token' => 'pinterest-token',
    ]);

    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => Http::response([
            'items' => [
                ['id' => 'board_1', 'name' => 'Ideas', 'privacy' => 'PUBLIC'],
                ['id' => 'board_2', 'name' => 'Product'],
            ],
        ], 200),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListPinterestBoardsTool::class, ['account_id' => $account->id]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('boards', 2)
                ->where('boards.0.id', 'board_1')
                ->where('boards.0.name', 'Ideas')
                ->where('boards.1.id', 'board_2')
                ->where('boards.1.name', 'Product')
                ->where('truncated', false);
        });
});

test('returns an actionable error when pinterest token is expired', function () {
    $account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'access_token' => 'expired-token',
    ]);

    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => Http::response([
            'message' => 'Access token has expired or been revoked',
        ], 401),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListPinterestBoardsTool::class, ['account_id' => $account->id]);

    $response->assertHasErrors(['Access token has expired or been revoked']);
});

test('rejects non-pinterest accounts', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListPinterestBoardsTool::class, ['account_id' => $account->id]);

    $response->assertHasErrors(['This tool only works with Pinterest social accounts.']);
});

test('cannot list boards for another workspace account', function () {
    $otherWorkspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListPinterestBoardsTool::class, ['account_id' => $account->id]);

    $response->assertHasErrors(['Social account not found.']);
});

test('validates account_id is required', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(ListPinterestBoardsTool::class, []);

    $response->assertHasErrors();
});

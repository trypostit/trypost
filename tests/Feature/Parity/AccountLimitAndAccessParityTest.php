<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\SocialAccount\ListSocialAccountsTool;
use App\Mcp\Tools\Workspace\GetWorkspaceTool;
use App\Models\SocialAccount;
use App\Models\User;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

function accountLimitMcpAccounts(User $user): array
{
    $accounts = null;

    TryPostServer::actingAs($user)->tool(ListSocialAccountsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function ($json) use (&$accounts) {
            $accounts = $json->toArray()['social_accounts'];
            $json->etc();
        });

    return $accounts;
}

function accountLimitMcpWorkspace(User $user): array
{
    $workspace = null;

    TryPostServer::actingAs($user)->tool(GetWorkspaceTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function ($json) use (&$workspace) {
            $workspace = $json->toArray();
            $json->etc();
        });

    return $workspace;
}

test('each account lists its own text limit through api and mcp', function () {
    $longX = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'meta' => ['x_subscription_type' => 'Premium']]);
    $plainX = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X, 'meta' => []]);
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $api = collect($this->withHeaders(parityApi($this->token))->getJson(route('api.social-accounts.index'))->assertOk()->json('data'))->keyBy('id');
    $mcp = collect(accountLimitMcpAccounts($this->user))->keyBy('id');

    foreach ([$api, $mcp] as $listing) {
        expect($listing[$longX->id]['max_content_length'])->toBe(25000)
            ->and($listing[$plainX->id]['max_content_length'])->toBe(280)
            ->and($listing[$linkedin->id]['max_content_length'])->toBe(3000);
    }
});

test('the workspace says what the caller may do through mcp', function (string $access, array $expected) {
    $member = User::factory()->create(['account_id' => $this->workspace->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot($access));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    expect(accountLimitMcpWorkspace($member->fresh())['me'])->toBe($expected);
})->with([
    'admin' => ['admin', ['is_admin' => true, 'requires_approval' => false, 'publishes_directly' => true]],
    'member' => ['member', ['is_admin' => false, 'requires_approval' => false, 'publishes_directly' => true]],
    'member who needs approval' => ['approval', ['is_admin' => false, 'requires_approval' => true, 'publishes_directly' => false]],
]);

test('the workspace owner is an admin who publishes directly through api and mcp', function () {
    $expected = ['is_admin' => true, 'requires_approval' => false, 'publishes_directly' => true];

    expect($this->withHeaders(parityApi($this->token))->getJson(route('api.workspace.show'))->assertOk()->json('me'))->toBe($expected)
        ->and(accountLimitMcpWorkspace($this->user)['me'])->toBe($expected);
});

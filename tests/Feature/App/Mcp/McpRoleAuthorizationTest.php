<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Label\CreateLabelTool;
use App\Mcp\Tools\Label\DeleteLabelTool;
use App\Mcp\Tools\Label\ListLabelsTool;
use App\Mcp\Tools\Label\UpdateLabelTool;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\RequestMediaUploadTool;
use App\Mcp\Tools\Signature\CreateSignatureTool;
use App\Mcp\Tools\Signature\DeleteSignatureTool;
use App\Mcp\Tools\Signature\ListSignaturesTool;
use App\Mcp\Tools\Signature\UpdateSignatureTool;
use App\Mcp\Tools\SocialAccount\ListDiscordChannelsTool;
use App\Mcp\Tools\SocialAccount\ListPinterestBoardsTool;
use App\Mcp\Tools\SocialAccount\ListSocialAccountsTool;
use App\Mcp\Tools\Webhook\CreateWebhookTool;
use App\Mcp\Tools\Webhook\ListWebhooksTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);

    $this->requester = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->workspace->members()->attach($this->requester->id, membershipPivot('approval'));
    $this->requester->update(['current_workspace_id' => $this->workspace->id]);

    $this->member = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->workspace->members()->attach($this->member->id, membershipPivot('member'));
    $this->member->update(['current_workspace_id' => $this->workspace->id]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]);
});

test('members who need approval can list labels signatures and social accounts via mcp', function () {
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    TryPostServer::actingAs($this->requester)
        ->tool(ListLabelsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('labels', 1)->etc());

    TryPostServer::actingAs($this->requester)
        ->tool(ListSignaturesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('signatures', 1)->etc());

    TryPostServer::actingAs($this->requester)
        ->tool(ListSocialAccountsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('social_accounts', 1)->etc());
});

test('a user outside the workspace cannot publish attach media or request uploads via mcp', function () {
    $outsider = workspaceOutsider($this->workspace);

    $uploadToken = (string) Str::uuid();
    Media::factory()->temporaryUpload($this->workspace)->create(['upload_token' => $uploadToken]);

    TryPostServer::actingAs($outsider)
        ->tool(PublishPostTool::class, ['post_id' => $this->post->id])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'https://example.com/photo.jpg']],
        ])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $uploadToken,
        ])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(RequestMediaUploadTool::class, [])
        ->assertHasErrors(['This action is unauthorized.']);
});

test('a user outside the workspace cannot manage labels or signatures via mcp', function () {
    $outsider = workspaceOutsider($this->workspace);

    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($outsider)
        ->tool(CreateLabelTool::class, ['name' => 'Nope', 'color' => '#112233'])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(UpdateLabelTool::class, [
            'label_id' => $label->id,
            'name' => 'Nope',
            'color' => '#112233',
        ])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(DeleteLabelTool::class, ['label_id' => $label->id])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(CreateSignatureTool::class, ['name' => 'Nope', 'content' => 'x'])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(UpdateSignatureTool::class, [
            'signature_id' => $signature->id,
            'name' => 'Nope',
            'content' => 'x',
        ])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(DeleteSignatureTool::class, ['signature_id' => $signature->id])
        ->assertHasErrors(['This action is unauthorized.']);

    expect($label->fresh())->not->toBeNull()
        ->and($signature->fresh())->not->toBeNull();
});

test('a user outside the workspace cannot list compose helpers via mcp', function () {
    $outsider = workspaceOutsider($this->workspace);

    $discord = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $pinterest = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($outsider)
        ->tool(ListDiscordChannelsTool::class, ['account_id' => $discord->id])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($outsider)
        ->tool(ListPinterestBoardsTool::class, ['account_id' => $pinterest->id])
        ->assertHasErrors(['This action is unauthorized.']);
});

test('members who are not admins cannot list or create webhooks via mcp', function (string $role) {
    $user = $role === 'approval' ? $this->requester : $this->member;

    TryPostServer::actingAs($user)
        ->tool(ListWebhooksTool::class, [])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($user)
        ->tool(CreateWebhookTool::class, [
            'endpoint' => 'https://example.com/webhooks',
            'events' => ['post.published'],
        ])
        ->assertHasErrors(['This action is unauthorized.']);
})->with([
    'approval',
    'member',
]);

test('admins can list webhooks via mcp', function () {
    TryPostServer::actingAs($this->owner)
        ->tool(ListWebhooksTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('webhooks', 0)->etc());
});

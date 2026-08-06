<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
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
use App\Mcp\Tools\SocialAccount\ToggleSocialAccountTool;
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
    $this->workspace->members()->attach($this->owner->id, ['role' => Role::Admin->value]);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);

    $this->viewer = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->workspace->members()->attach($this->viewer->id, ['role' => Role::Viewer->value]);
    $this->viewer->update(['current_workspace_id' => $this->workspace->id]);

    $this->member = User::factory()->create(['account_id' => $this->owner->account_id]);
    $this->workspace->members()->attach($this->member->id, ['role' => Role::Member->value]);
    $this->member->update(['current_workspace_id' => $this->workspace->id]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]);
});

test('viewers can list labels signatures and social accounts via mcp', function () {
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    TryPostServer::actingAs($this->viewer)
        ->tool(ListLabelsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('labels', 1)->etc());

    TryPostServer::actingAs($this->viewer)
        ->tool(ListSignaturesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('signatures', 1)->etc());

    TryPostServer::actingAs($this->viewer)
        ->tool(ListSocialAccountsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('social_accounts', 1)->etc());
});

test('viewers cannot publish attach media or request uploads via mcp', function () {
    $uploadToken = (string) Str::uuid();
    Media::factory()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'collection' => 'assets',
        'upload_token' => $uploadToken,
    ]);

    TryPostServer::actingAs($this->viewer)
        ->tool(PublishPostTool::class, ['post_id' => $this->post->id])
        ->assertHasErrors(['Not authorized to publish this post.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'https://example.com/photo.jpg']],
        ])
        ->assertHasErrors(['Not authorized to update this post.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $uploadToken,
        ])
        ->assertHasErrors(['Not authorized to update this post.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(RequestMediaUploadTool::class, [])
        ->assertHasErrors(['Not authorized to upload media.']);
});

test('viewers cannot manage labels or signatures via mcp', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->viewer)
        ->tool(CreateLabelTool::class, ['name' => 'Nope', 'color' => '#112233'])
        ->assertHasErrors(['Not authorized to manage labels.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(UpdateLabelTool::class, [
            'label_id' => $label->id,
            'name' => 'Nope',
            'color' => '#112233',
        ])
        ->assertHasErrors(['Not authorized to manage labels.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(DeleteLabelTool::class, ['label_id' => $label->id])
        ->assertHasErrors(['Not authorized to manage labels.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(CreateSignatureTool::class, ['name' => 'Nope', 'content' => 'x'])
        ->assertHasErrors(['Not authorized to manage signatures.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(UpdateSignatureTool::class, [
            'signature_id' => $signature->id,
            'name' => 'Nope',
            'content' => 'x',
        ])
        ->assertHasErrors(['Not authorized to manage signatures.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(DeleteSignatureTool::class, ['signature_id' => $signature->id])
        ->assertHasErrors(['Not authorized to manage signatures.']);

    expect($label->fresh())->not->toBeNull()
        ->and($signature->fresh())->not->toBeNull();
});

test('viewers cannot toggle social accounts or list compose helpers via mcp', function () {
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'is_active' => true,
    ]);
    $discord = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $pinterest = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->viewer)
        ->tool(ToggleSocialAccountTool::class, ['account_id' => $linkedin->id])
        ->assertHasErrors(['Not authorized to manage social accounts.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(ListDiscordChannelsTool::class, ['account_id' => $discord->id])
        ->assertHasErrors(['Not authorized to manage posts.']);

    TryPostServer::actingAs($this->viewer)
        ->tool(ListPinterestBoardsTool::class, ['account_id' => $pinterest->id])
        ->assertHasErrors(['Not authorized to manage posts.']);

    expect($linkedin->fresh()->is_active)->toBeTrue();
});

test('members cannot toggle social accounts via mcp', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'is_active' => true,
    ]);

    TryPostServer::actingAs($this->member)
        ->tool(ToggleSocialAccountTool::class, ['account_id' => $account->id])
        ->assertHasErrors(['Not authorized to manage social accounts.']);

    expect($account->fresh()->is_active)->toBeTrue();
});

test('admins can toggle social accounts via mcp', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'is_active' => true,
    ]);

    TryPostServer::actingAs($this->owner)
        ->tool(ToggleSocialAccountTool::class, ['account_id' => $account->id])
        ->assertOk();

    expect($account->fresh()->is_active)->toBeFalse();
});

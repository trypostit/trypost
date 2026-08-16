<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Asset\AttachExistingAssetTool;
use App\Mcp\Tools\Asset\GetAssetTool;
use App\Mcp\Tools\Asset\ListAssetsTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostStatusRules;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Storage::fake();
});

test('lists current workspace assets with the asset resource shape', function () {
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'hero.jpg',
    ]);
    Media::factory()->logo()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);
    $other = Workspace::factory()->create();
    Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $other->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($asset) {
            $json->has('assets', 1, function (AssertableJson $item) use ($asset) {
                $item->where('id', $asset->id)
                    ->where('original_filename', 'hero.jpg')
                    ->where('type', MediaType::Image->value)
                    ->hasAll(['mime_type', 'size', 'url', 'meta', 'created_at'])
                    ->missing('path');
            });
        });
});

test('filters and limits listed assets', function () {
    Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'one.jpg',
    ]);
    Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'two.jpg',
    ]);
    Media::factory()->assets()->video()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'reel.mp4',
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, ['type' => 'image', 'limit' => 1])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('assets', 1)->etc());
});

test('returns a workspace asset', function () {
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(GetAssetTool::class, ['asset_id' => $asset->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($asset) {
            $json->where('id', $asset->id)
                ->where('url', $asset->url)
                ->hasAll(['original_filename', 'type', 'mime_type', 'size', 'meta', 'created_at'])
                ->missing('path');
        });
});

test('missing and cross workspace assets do not reveal metadata', function () {
    $other = Workspace::factory()->create();
    $foreign = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $other->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(GetAssetTool::class, ['asset_id' => $foreign->id])
        ->assertHasErrors(['Asset not found.']);

    TryPostServer::actingAs($this->user)
        ->tool(GetAssetTool::class, ['asset_id' => (string) Str::uuid()])
        ->assertHasErrors(['Asset not found.']);
});

test('attaches an existing workspace asset once', function () {
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
            'alt' => 'Hero image',
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('post.id');
        });

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('post.id')->etc();
        });

    expect($this->post->fresh()->media)->toHaveCount(1)
        ->and(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBe('Hero image');
});

test('does not store alt text for existing non-image assets', function () {
    $asset = Media::factory()->assets()->video()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
            'alt' => 'ignored',
        ])
        ->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta'))->toBeNull();
});

test('rejects cross-workspace assets and posts without mutating the post', function () {
    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $other->id]);
    $foreignAsset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $otherWorkspace->id,
    ]);
    $foreignPost = Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $other->id,
    ]);
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $foreignAsset->id,
        ])
        ->assertHasErrors(['Asset not found.']);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $foreignPost->id,
            'asset_id' => $asset->id,
        ])
        ->assertHasErrors(['Post not found.']);

    expect($this->post->fresh()->media)->toHaveCount(0);
});

test('rejects posts in non-editable states', function () {
    $this->post->update(['status' => PostStatus::Published]);
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
        ])
        ->assertHasErrors([PostStatusRules::editBlockedMessage()]);
});

test('rejects assets that enabled post platforms cannot publish', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::TikTok,
    ]);
    PostPlatform::factory()->tiktok()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
    ]);

    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
        ])
        ->assertHasErrors(['No enabled platform on this post accepts this media type.']);
});

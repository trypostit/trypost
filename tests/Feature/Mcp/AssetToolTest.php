<?php

declare(strict_types=1);

use App\Actions\Post\AttachExistingAsset;
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
            })->where('has_more', false);
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
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('assets', 1)->where('has_more', true));

    TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, ['search' => 'reel', 'type' => 'video'])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('assets', 1, function (AssertableJson $item) {
                $item->where('original_filename', 'reel.mp4')->etc();
            })->where('has_more', false);
        });

    TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, ['type' => 'image', 'limit' => 2])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('assets', 2)->where('has_more', false));
});

test('rejects out of range list limits', function (int $limit) {
    TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, ['limit' => $limit])
        ->assertHasErrors();
})->with([0, 101]);

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

test('does not return a logo or avatar from the asset library', function () {
    $logo = Media::factory()->logo()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);
    $avatar = Media::factory()->avatar()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(GetAssetTool::class, ['asset_id' => $logo->id])
        ->assertHasErrors(['Asset not found.']);

    TryPostServer::actingAs($this->user)
        ->tool(GetAssetTool::class, ['asset_id' => $avatar->id])
        ->assertHasErrors(['Asset not found.']);
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
        'size' => 12345,
        'meta' => [
            'width' => 1920,
            'height' => 1080,
            'duration' => 12.5,
            'color_space' => 'srgb',
        ],
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
            'alt' => 'Replacement alt',
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('post.id')->etc();
        });

    expect($this->post->fresh()->media)->toHaveCount(1)
        ->and(data_get($this->post->fresh()->media, '0.size'))->toBe(12345)
        ->and(data_get($this->post->fresh()->media, '0.meta'))->toBe([
            'width' => 1920,
            'height' => 1080,
            'duration' => 12.5,
            'color_space' => 'srgb',
            'alt_text' => 'Hero image',
        ]);
});

test('preserves library alt text when attach omits alt', function () {
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'meta' => [
            'width' => 800,
            'height' => 600,
            'alt_text' => 'From library',
        ],
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
        ])
        ->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta'))->toBe([
        'width' => 800,
        'height' => 600,
        'alt_text' => 'From library',
    ]);
});

test('attaches an existing document asset without inventing meta', function () {
    $asset = Media::factory()->assets()->document()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'meta' => [],
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(AttachExistingAssetTool::class, [
            'post_id' => $this->post->id,
            'asset_id' => $asset->id,
            'alt' => 'ignored for pdf',
        ])
        ->assertOk();

    expect($this->post->fresh()->media)->toHaveCount(1)
        ->and(data_get($this->post->fresh()->media, '0.type'))->toBe('document')
        ->and(data_get($this->post->fresh()->media, '0.meta'))->toBeNull();
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

    expect(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBeNull()
        ->and(data_get($this->post->fresh()->media, '0.meta.duration'))->not->toBeNull();
});

test('attaches an existing asset to a scheduled post', function () {
    $this->post->update([
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
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
        ->assertOk();

    expect($this->post->fresh()->media)->toHaveCount(1);
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

test('rejects posts in non-editable states', function (PostStatus $status) {
    $this->post->update(['status' => $status]);
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
})->with([
    PostStatus::Published,
    PostStatus::PartiallyPublished,
    PostStatus::Failed,
    PostStatus::Publishing,
]);

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
        ->assertHasErrors([AttachExistingAsset::UNSUPPORTED_TYPE_MESSAGE]);
});

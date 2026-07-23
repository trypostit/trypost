<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Asset\ListAssetsTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-22 12:00:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function assetForList(Workspace $workspace, array $attributes = []): Media
{
    return Media::factory()->create(array_merge([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
        'collection' => 'assets',
    ], $attributes));
}

test('lists current workspace asset library media with usage metadata and safe payload shape', function () {
    $asset = assetForList($this->workspace, [
        'original_filename' => 'finish-line.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 12345,
        'meta' => ['width' => 1920, 'height' => 1080],
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [['id' => $asset->id, 'path' => $asset->path]],
        'status' => PostStatus::Published,
    ]);
    PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'published_at' => '2026-07-21 10:00:00',
    ]);

    $otherWorkspace = Workspace::factory()->create();
    assetForList($otherWorkspace, ['original_filename' => 'foreign.jpg']);
    Media::factory()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'collection' => 'avatar',
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, ['per_page' => 25]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($asset) {
            $json->has('assets', 1, function (AssertableJson $json) use ($asset) {
                $json->where('asset_id', $asset->id)
                    ->where('filename', 'finish-line.jpg')
                    ->where('category', 'image')
                    ->where('mime_type', 'image/jpeg')
                    ->where('size_bytes', 12345)
                    ->where('width', 1920)
                    ->where('height', 1080)
                    ->where('is_used', true)
                    ->where('content_usage_count', 1)
                    ->where('usage_count', 1)
                    ->where('publication_usage_count', 1)
                    ->where('timestamped_publication_usage_count', 1)
                    ->where('last_used_at', '2026-07-21T10:00:00+00:00')
                    ->where('days_since_last_use', 1)
                    ->has('last_use_contexts', 1)
                    ->missing('path')
                    ->missing('url')
                    ->missing('workspace_id')
                    ->etc();
            })->where('pagination.per_page', 25)
                ->where('pagination.total', 1)
                ->etc();
        });
});

test('filters and sorts assets by metadata and usage without conflating configured and published rows', function () {
    $used = assetForList($this->workspace, [
        'original_filename' => 'used-race.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    $unused = assetForList($this->workspace, [
        'original_filename' => 'unused-race.mp4',
        'mime_type' => 'video/mp4',
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [['id' => $used->id, 'path' => $used->path]],
        'status' => PostStatus::PartiallyPublished,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'enabled' => true,
        'status' => PostPlatformStatus::Pending,
        'published_at' => null,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, [
            'usage' => 'used',
            'category' => 'image',
            'sort' => 'publication_usage_count',
            'direction' => 'desc',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($used) {
            $json->has('assets', 1)
                ->where('assets.0.asset_id', $used->id)
                ->where('assets.0.configured_platforms.0', 'linkedin')
                ->where('assets.0.published_platforms', [])
                ->where('assets.0.publication_usage_count', 0)
                ->etc();
        });

    TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, ['usage' => 'unused'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('assets.0.asset_id', $unused->id)->etc());
});

test('metadata sorted listing computes usage only for the current page', function () {
    $firstPage = assetForList($this->workspace, [
        'original_filename' => 'first-page.jpg',
        'created_at' => CarbonImmutable::parse('2026-07-22 12:03:00', 'UTC'),
    ]);
    $offPage = assetForList($this->workspace, [
        'original_filename' => 'off-page.jpg',
        'created_at' => CarbonImmutable::parse('2026-07-22 12:02:00', 'UTC'),
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [['id' => $offPage->id, 'path' => $offPage->path]],
        'status' => PostStatus::Published,
    ]);
    PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'published_at' => '2026-07-21 10:00:00',
    ]);

    $postQueryBindings = [];
    DB::listen(function ($query) use (&$postQueryBindings) {
        if (str_contains($query->sql, 'from "posts"')) {
            $postQueryBindings[] = $query->bindings;
        }
    });

    TryPostServer::actingAs($this->user)
        ->tool(ListAssetsTool::class, [
            'per_page' => 1,
            'sort' => 'created_at',
            'direction' => 'desc',
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($firstPage) {
            $json->where('assets.0.asset_id', $firstPage->id)
                ->where('assets.0.is_used', false)
                ->where('pagination.total', 2)
                ->where('pagination.has_more', true)
                ->etc();
        });

    $encodedBindings = json_encode($postQueryBindings, JSON_THROW_ON_ERROR);

    expect($encodedBindings)->toContain($firstPage->id)
        ->and($encodedBindings)->not->toContain($offPage->id);
});

test('schema does not accept workspace_id', function () {
    $schema = (new ListAssetsTool)->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKeys(['page', 'per_page', 'search', 'mime_type', 'category', 'usage', 'sort', 'direction'])
        ->and($schema)->not->toHaveKey('workspace_id');
});

<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Asset\GetAssetPreviewTool;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/preview.jpg', 'preview-bytes');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

function mcpPreviewAsset(Workspace $workspace, array $attributes = []): Media
{
    return Media::factory()->create(array_merge([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
        'collection' => 'assets',
        'path' => 'media/preview.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 12,
    ], $attributes));
}

test('returns a temporary preview for a workspace asset', function () {
    $media = mcpPreviewAsset($this->workspace);

    TryPostServer::actingAs($this->user)
        ->tool(GetAssetPreviewTool::class, ['asset_id' => $media->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($media) {
            $json->where('asset_id', $media->id)
                ->where('mime_type', 'image/jpeg')
                ->where('size_bytes', 12)
                ->where('preview_mode', 'signed_route')
                ->has('preview_url')
                ->has('expires_at')
                ->missing('path')
                ->missing('url')
                ->etc();
        });
});

test('missing and cross workspace assets do not reveal metadata', function () {
    $otherWorkspace = Workspace::factory()->create();
    $foreignMedia = mcpPreviewAsset($otherWorkspace);

    TryPostServer::actingAs($this->user)
        ->tool(GetAssetPreviewTool::class, ['asset_id' => $foreignMedia->id])
        ->assertHasErrors();
});

test('missing storage object returns preview unavailable', function () {
    $media = mcpPreviewAsset($this->workspace, ['path' => 'media/missing.jpg']);

    TryPostServer::actingAs($this->user)
        ->tool(GetAssetPreviewTool::class, ['asset_id' => $media->id])
        ->assertHasErrors();
});

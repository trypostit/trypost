<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\RequestMediaUploadTool;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Storage::fake();
    Cache::flush();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
});

test('agent can request URL, client uploads, agent attaches to post', function () {
    $uploadUrl = null;
    $uploadToken = null;

    TryPostServer::actingAs($this->user)
        ->tool(RequestMediaUploadTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$uploadUrl, &$uploadToken) {
            $json->etc();
            $uploadUrl = $json->toArray()['upload_url'];
            $uploadToken = $json->toArray()['upload_token'];
        });

    expect($uploadUrl)->not->toBeNull();
    expect($uploadToken)->not->toBeNull();

    $file = UploadedFile::fake()->image('e2e.png', 64, 64);
    $this->post($uploadUrl, ['media' => $file])->assertCreated();

    TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $uploadToken,
        ])
        ->assertOk();

    expect($this->post->fresh()->media)->toHaveCount(1);
    expect(data_get($this->post->fresh()->media, '0.size'))->toBeInt()->toBeGreaterThan(0);
});

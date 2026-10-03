<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\RequestMediaUploadTool;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('returns a single-use signed upload URL', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(RequestMediaUploadTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('upload_token')
                ->has('upload_url')
                ->has('expires_at')
                ->where('max_bytes', MediaType::Video->maxSizeInBytes())
                ->where('max_bytes_by_type.image', MediaType::Image->maxSizeInBytes())
                ->where('max_bytes_by_type.video', MediaType::Video->maxSizeInBytes())
                ->where('max_bytes_by_type.document', MediaType::Document->maxSizeInBytes())
                ->where('field_name', 'media')
                ->etc();
        });
});

test('signed URL is valid against the api.uploads.store route', function () {
    $uploadUrl = null;

    TryPostServer::actingAs($this->user)
        ->tool(RequestMediaUploadTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$uploadUrl) {
            $json->etc();
            $uploadUrl = $json->toArray()['upload_url'];
        });

    expect(URL::hasValidSignature(
        request()->create($uploadUrl, 'POST'),
    ))->toBeTrue();
});

test('each call returns a distinct upload_token', function () {
    $firstToken = null;
    $secondToken = null;

    TryPostServer::actingAs($this->user)
        ->tool(RequestMediaUploadTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$firstToken) {
            $json->etc();
            $firstToken = $json->toArray()['upload_token'];
        });

    TryPostServer::actingAs($this->user)
        ->tool(RequestMediaUploadTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$secondToken) {
            $json->etc();
            $secondToken = $json->toArray()['upload_token'];
        });

    expect($firstToken)->not->toBe($secondToken);
});

<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

test('attaches an image from url and creates a media row', function () {
    Http::fake([
        'example.com/photo.jpg' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'https://example.com/photo.jpg']],
        ]);

    $response->assertOk();

    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(1);
    expect($this->post->fresh()->media)->toHaveCount(1);
});

test('attaches an image from url with alt text and stores it in meta', function () {
    Http::fake([
        'example.com/photo.jpg' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'https://example.com/photo.jpg', 'alt' => 'A red bicycle by a wall']],
        ]);

    $response->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBe('A red bicycle by a wall');
});

test('rejects url that returns non-image content type', function () {
    Http::fake([
        'example.org/payload' => Http::response('not an image', 200, ['Content-Type' => 'text/html']),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'https://example.org/payload']],
        ]);

    $response->assertOk();

    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(0);
    expect($this->post->fresh()->media)->toHaveCount(0);
});

test('reports failures and successes separately', function () {
    Http::fake([
        'example.com/ok.png' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
        'example.com/missing.png' => Http::response(null, 404),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [
                ['url' => 'https://example.com/ok.png'],
                ['url' => 'https://example.com/missing.png'],
            ],
        ]);

    $response->assertOk()
        ->assertSee(['example.com/missing.png']);

    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(1);
    expect($this->post->fresh()->media)->toHaveCount(1);
});

test('attaches a pdf document from a url to a LinkedIn post', function () {
    Http::fake([
        'example.com/deck.pdf' => Http::response(
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);

    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    PostPlatform::factory()->create([
        'post_id' => $this->post->id, 'social_account_id' => $linkedin->id,
        'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost, 'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'https://example.com/deck.pdf']],
        ]);

    $response->assertOk();

    $media = $this->post->fresh()->media;
    expect($media)->toHaveCount(1)
        ->and($media[0]['type'])->toBe('document');
});

test('rejects a pdf url for a post with no PDF-capable platform', function () {
    Http::fake([
        'example.com/deck.pdf' => Http::response(
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);

    $tiktok = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::TikTok]);
    PostPlatform::factory()->create([
        'post_id' => $this->post->id, 'social_account_id' => $tiktok->id,
        'platform' => Platform::TikTok, 'content_type' => ContentType::TikTokVideo, 'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'https://example.com/deck.pdf']],
        ]);

    $response->assertOk();

    expect($this->post->fresh()->media)->toHaveCount(0);
});

test('post 404 from another workspace', function () {
    $other = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $other->id, 'user_id' => $this->user->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $post->id,
            'urls' => [['url' => 'https://example.com/photo.jpg']],
        ]);

    $response->assertHasErrors(['Post not found.']);
});

test('rejects urls with non-http(s) schemes', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'ftp://example.com/photo.jpg']],
        ]);

    $response->assertHasErrors();

    expect($this->post->fresh()->media)->toBeEmpty();
});

test('rejects malformed url strings', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'not-a-url-at-all']],
        ]);

    $response->assertHasErrors();
});

test('rejects alt text over the max length', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => [['url' => 'https://example.com/photo.jpg', 'alt' => str_repeat('a', 2001)]],
        ]);

    $response->assertHasErrors();
});

test('rejects the old bare-string urls shape', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => ['https://example.com/photo.jpg'],
        ]);

    $response->assertHasErrors();
});

test('rejects more than 10 urls per call', function () {
    $urls = collect(range(1, 11))->map(fn ($i) => ['url' => "https://example.com/photo-{$i}.jpg"])->all();

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, [
            'post_id' => $this->post->id,
            'urls' => $urls,
        ]);

    $response->assertHasErrors();
});

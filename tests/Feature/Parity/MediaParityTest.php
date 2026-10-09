<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\RequestMediaUploadTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function mediaParityUpload(object $test, string $kind = 'image'): Media
{
    $factory = Media::factory()->temporaryUpload($test->workspace)->stored();
    $upload = ($kind === 'video' ? $factory->video() : $factory)->create();

    return $upload;
}

function mediaParityPost(object $test, string $platform = 'linkedin'): Post
{
    $account = SocialAccount::factory()->create([
        'workspace_id' => $test->workspace->id,
        'platform' => match ($platform) {
            'tiktok' => Platform::TikTok,
            'youtube' => Platform::YouTube,
            default => Platform::LinkedIn,
        },
    ]);

    return Post::factory()->forAccount($account)->{$platform}()->create(['user_id' => $test->user->id]);
}

function mediaParityShape(Post $post): array
{
    return collect($post->fresh()->media)->map(fn (array $item): array => [
        'type' => data_get($item, 'type'),
        'mime_type' => data_get($item, 'mime_type'),
        'alt_text' => data_get($item, 'meta.alt_text'),
    ])->all();
}

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    Storage::fake();
});

test('attaching an image from a url gives the same media through api and mcp', function () {
    Http::fake(['example.com/*' => fn () => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png'])]);
    $apiPost = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $mcpPost = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $urls = [['url' => 'https://example.com/a.png', 'alt' => 'A pixel']];

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.attach-media-from-url', $apiPost), ['urls' => $urls])
        ->assertSuccessful();
    TryPostServer::actingAs($this->user)->tool(AttachMediaFromUrlTool::class, ['post_id' => $mcpPost->id, 'urls' => $urls])->assertOk();

    expect(mediaParityShape($apiPost))->toEqual(mediaParityShape($mcpPost))
        ->toEqual([['type' => 'image', 'mime_type' => 'image/png', 'alt_text' => 'A pixel']]);
});

test('a url that is not an allowed file is reported as failed by both surfaces', function () {
    Http::fake(['example.com/*' => fn () => Http::response('<html></html>', 200, ['Content-Type' => 'text/html'])]);
    $apiPost = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $mcpPost = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $urls = [['url' => 'https://example.com/page.html']];

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.attach-media-from-url', $apiPost), ['urls' => $urls])
        ->assertSuccessful()
        ->assertJsonPath('failed_urls', ['https://example.com/page.html']);
    TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUrlTool::class, ['post_id' => $mcpPost->id, 'urls' => $urls])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('failed_urls', ['https://example.com/page.html'])->where('attached_count', 0)->etc());

    expect($apiPost->fresh()->media)->toBeEmpty()->and($mcpPost->fresh()->media)->toBeEmpty();
});

test('attaching an upload gives the same media through api and mcp', function () {
    $apiPost = mediaParityPost($this);
    $mcpPost = mediaParityPost($this);
    $apiUpload = mediaParityUpload($this);
    $mcpUpload = mediaParityUpload($this);

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.attach-media-from-upload', $apiPost), ['upload_token' => $apiUpload->upload_token, 'alt' => 'Alt text'])
        ->assertSuccessful();
    TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, ['post_id' => $mcpPost->id, 'upload_token' => $mcpUpload->upload_token, 'alt' => 'Alt text'])
        ->assertOk();

    expect(mediaParityShape($apiPost))->toEqual(mediaParityShape($mcpPost))
        ->toEqual([['type' => 'image', 'mime_type' => 'image/jpeg', 'alt_text' => 'Alt text']])
        ->and($apiPost->ownedMedia()->count())->toBe(1)
        ->and($mcpPost->ownedMedia()->count())->toBe(1);
});

test('an expired or unknown upload token is refused by both surfaces', function () {
    $apiPost = mediaParityPost($this);
    $mcpPost = mediaParityPost($this);
    $token = (string) Str::uuid();

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.attach-media-from-upload', $apiPost), ['upload_token' => $token])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['upload_token' => __('posts.errors.media_expired')]);
    TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, ['post_id' => $mcpPost->id, 'upload_token' => $token])
        ->assertHasErrors([__('posts.errors.media_expired')]);

    expect($apiPost->fresh()->media)->toBeEmpty()->and($mcpPost->fresh()->media)->toBeEmpty();
});

test('a media type the enabled network does not accept is refused by both surfaces', function () {
    $apiPost = mediaParityPost($this, 'youtube');
    $mcpPost = mediaParityPost($this, 'youtube');
    $apiUpload = mediaParityUpload($this);
    $mcpUpload = mediaParityUpload($this);

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.attach-media-from-upload', $apiPost), ['upload_token' => $apiUpload->upload_token])
        ->assertUnprocessable();
    TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, ['post_id' => $mcpPost->id, 'upload_token' => $mcpUpload->upload_token])
        ->assertHasErrors();

    expect($apiPost->fresh()->media)->toBeEmpty()->and($mcpPost->fresh()->media)->toBeEmpty();
});

test('a file upload through the api and through the mcp signed url accepts the same types and caps', function () {
    $cases = [
        'image' => [UploadedFile::fake()->image('photo.jpg', 20, 20), true],
        'pdf' => [UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'), true],
        'text' => [UploadedFile::fake()->create('notes.txt', 5, 'text/plain'), false],
        'oversized image' => [UploadedFile::fake()->image('big.jpg', 20, 20)->size(MediaType::Image->maxSizeInKb() + 1), false],
    ];

    foreach ($cases as $label => [$file, $accepted]) {
        $api = $this->withHeaders(parityApi($this->token))->post(route('api.uploads.create'), ['media' => $file]);

        $issued = null;
        TryPostServer::actingAs($this->user)->tool(RequestMediaUploadTool::class, [])
            ->assertOk()
            ->assertStructuredContent(function ($json) use (&$issued) {
                $issued = $json->toArray();

                return $json->etc();
            });
        auth()->forgetGuards();
        $signed = $this->post(data_get($issued, 'upload_url'), ['media' => $file], ['Accept' => 'application/json']);

        expect($api->status())->toBe($accepted ? 201 : 422, $label)
            ->and($signed->status())->toBe($accepted ? 201 : 422, $label);
    }
});

test('both surfaces issue the same size ceilings for an upload', function () {
    $this->withHeaders(parityApi($this->token))
        ->post(route('api.uploads.create'), ['media' => UploadedFile::fake()->image('p.jpg', 10, 10)])
        ->assertCreated();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(RequestMediaUploadTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('max_bytes_by_type.image', MediaType::Image->maxSizeInBytes())
            ->where('max_bytes_by_type.video', MediaType::Video->maxSizeInBytes())
            ->where('max_bytes_by_type.document', MediaType::Document->maxSizeInBytes())
            ->etc());
});

test('manual alt text set through the api update and the mcp update tool is stored the same way', function () {
    $apiPost = mediaParityPost($this);
    $mcpPost = mediaParityPost($this);
    $apiUpload = mediaParityUpload($this);
    $mcpUpload = mediaParityUpload($this);

    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft', 'media' => [['upload_token' => $apiUpload->upload_token, 'alt' => 'Manual alt']]])
        ->assertSuccessful();
    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'media' => [['upload_token' => $mcpUpload->upload_token, 'alt' => 'Manual alt']]])
        ->assertOk();

    expect(mediaParityShape($apiPost))->toEqual(mediaParityShape($mcpPost))
        ->toEqual([['type' => 'image', 'mime_type' => 'image/jpeg', 'alt_text' => 'Manual alt']]);
});

test('removing and reordering media through the api update and the mcp update tool end the same way', function () {
    $posts = ['api' => mediaParityPost($this), 'mcp' => mediaParityPost($this)];

    foreach ($posts as $post) {
        foreach (['first', 'second'] as $name) {
            $upload = mediaParityUpload($this);
            $upload->update(['original_filename' => "{$name}.jpg"]);
            $post->appendMedia([MediaItem::fromMedia($upload)->toArray()]);
        }
    }

    $reorder = fn (Post $post): array => collect($post->fresh()->media)->reverse()->map(fn (array $item): array => ['id' => data_get($item, 'id')])->values()->all();
    $names = fn (Post $post): array => collect($post->fresh()->media)->pluck('original_filename')->all();

    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $posts['api']), ['status' => 'draft', 'media' => $reorder($posts['api'])])
        ->assertSuccessful();
    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $posts['mcp']->id, 'media' => $reorder($posts['mcp'])])
        ->assertOk();

    expect($names($posts['api']))->toBe(['second.jpg', 'first.jpg'])->and($names($posts['mcp']))->toBe(['second.jpg', 'first.jpg']);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $posts['api']), ['status' => 'draft', 'media' => []])
        ->assertSuccessful();
    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $posts['mcp']->id, 'media' => []])
        ->assertOk();

    expect($posts['api']->fresh()->media)->toBeEmpty()
        ->and($posts['mcp']->fresh()->media)->toBeEmpty()
        ->and($posts['api']->ownedMedia()->count())->toBe(0)
        ->and($posts['mcp']->ownedMedia()->count())->toBe(0);
});

test('user tags and a video cover frame set through the api update and the mcp update tool are stored the same way', function () {
    $apiPost = mediaParityPost($this);
    $mcpPost = mediaParityPost($this);
    $apiUpload = mediaParityUpload($this, 'video');
    $mcpUpload = mediaParityUpload($this, 'video');
    $meta = ['cover_offset_ms' => 1500];
    $tagged = mediaParityUpload($this);
    $taggedMcp = mediaParityUpload($this);
    $tags = [['username' => 'trypost', 'x' => 0.25, 'y' => 0.5]];

    $this->withHeaders(parityApi($this->token))
        ->putJson(route('api.posts.update', $apiPost), ['status' => 'draft', 'media' => [
            ['upload_token' => $apiUpload->upload_token, 'meta' => $meta],
            ['upload_token' => $tagged->upload_token, 'meta' => ['user_tags' => $tags]],
        ]])
        ->assertSuccessful();
    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $mcpPost->id, 'media' => [
            ['upload_token' => $mcpUpload->upload_token, 'meta' => $meta],
            ['upload_token' => $taggedMcp->upload_token, 'meta' => ['user_tags' => $tags]],
        ]])
        ->assertOk();

    $editable = fn (Post $post): array => collect($post->fresh()->media)->map(fn (array $item): array => [
        'cover_offset_ms' => data_get($item, 'meta.cover_offset_ms'),
        'user_tags' => data_get($item, 'meta.user_tags'),
    ])->all();

    expect($editable($apiPost))->toEqual($editable($mcpPost))
        ->toEqual([['cover_offset_ms' => 1500, 'user_tags' => null], ['cover_offset_ms' => null, 'user_tags' => $tags]]);
});

test('a media type the network does not accept gets the same translated message on both surfaces', function () {
    $apiPost = mediaParityPost($this, 'youtube');
    $mcpPost = mediaParityPost($this, 'youtube');
    $message = __('posts.errors.media_type_unsupported');

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.attach-media-from-upload', $apiPost), ['upload_token' => mediaParityUpload($this)->upload_token])
        ->assertJsonValidationErrors(['upload_token' => $message]);
    TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, ['post_id' => $mcpPost->id, 'upload_token' => mediaParityUpload($this)->upload_token])
        ->assertHasErrors([$message]);

    expect($message)->not->toContain('platforms enabled');
});

test('an alt text over the cap gets the same message on both surfaces', function () {
    $apiPost = mediaParityPost($this);
    $mcpPost = mediaParityPost($this);
    $alt = str_repeat('a', 2001);
    $message = trans('validation.max.string', ['attribute' => 'alt', 'max' => 2000]);

    $this->withHeaders(parityApi($this->token))
        ->postJson(route('api.posts.attach-media-from-upload', $apiPost), ['upload_token' => mediaParityUpload($this)->upload_token, 'alt' => $alt])
        ->assertJsonValidationErrors(['alt' => $message]);
    TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, ['post_id' => $mcpPost->id, 'upload_token' => mediaParityUpload($this)->upload_token, 'alt' => $alt])
        ->assertHasErrors([$message]);
});

test('an oversized file gets the same translated message through every upload route', function () {
    $post = mediaParityPost($this);
    $file = fn (): UploadedFile => UploadedFile::fake()->image('big.jpg', 20, 20)->size(MediaType::Image->maxSizeInKb() + 1);
    $message = __('posts.errors.media_file_too_large', ['size' => MediaType::Image->maxSizeInMb()]);

    $this->withHeaders(parityApi($this->token))
        ->post(route('api.posts.store-media', $post), ['media' => $file()])
        ->assertJsonValidationErrors(['media' => $message]);
    $this->withHeaders(parityApi($this->token))
        ->post(route('api.uploads.create'), ['media' => $file()])
        ->assertJsonValidationErrors(['media' => $message]);

    $issued = null;
    TryPostServer::actingAs($this->user)->tool(RequestMediaUploadTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function ($json) use (&$issued) {
            $issued = $json->toArray();

            return $json->etc();
        });
    auth()->forgetGuards();
    $this->post(data_get($issued, 'upload_url'), ['media' => $file()], ['Accept' => 'application/json'])
        ->assertJsonValidationErrors(['media' => $message]);
});

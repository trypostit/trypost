<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake(null, ['url' => 'https://cdn.example.com']);

    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];
    $this->channels = SocialAccount::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $this->channel = $this->channels->first();
});

function inlineMediaUpload(object $test): Media
{
    $upload = Media::factory()->temporaryUpload($test->workspace)->stored()->create();
    Storage::put($upload->path, 'bytes');

    return $upload;
}

function inlineMediaPost(object $test): Post
{
    $post = Post::factory()->create(['workspace_id' => $test->workspace->id, 'user_id' => $test->user->id]);
    PostPlatform::factory()->linkedin()->create(['post_id' => $post->id, 'social_account_id' => $test->channel->id, 'enabled' => true]);

    return $post;
}

function inlineMediaDestinations(object $test): array
{
    return $test->channels->map(fn (SocialAccount $channel): array => [
        'social_account_id' => $channel->id,
        'content_type' => ContentType::LinkedInPost->value,
    ])->all();
}

function inlineMediaFakeImage(): void
{
    Http::fake(['93.184.216.34/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png'])]);
}

test('an upload token moves to the post and is spent', function () {
    $upload = inlineMediaUpload($this);

    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'content' => 'Token',
        'media' => [['upload_token' => $upload->upload_token]],
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']],
    ])->assertCreated();

    $post = Post::query()->sole();

    expect($post->ownedMedia()->sole()->id)->toBe($upload->id)
        ->and($upload->fresh()->upload_token)->toBeNull()
        ->and($upload->fresh()->collection)->toBe(Media::COLLECTION_MEDIA);

    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'content' => 'Again',
        'media' => [['upload_token' => $upload->upload_token]],
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['media.0.upload_token' => __('posts.errors.media_expired')]);

    expect(Post::query()->count())->toBe(1);
});

test('a batch to three destinations gives each post its own row and file from one upload token', function () {
    $upload = inlineMediaUpload($this);

    $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), [
        'status' => 'draft',
        'media' => [['upload_token' => $upload->upload_token]],
        'destinations' => inlineMediaDestinations($this),
    ])->assertCreated();

    $rows = Media::query()->whereNotNull('post_id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('post_id')->unique())->toHaveCount(3)
        ->and($rows->pluck('path')->unique())->toHaveCount(3);
    $rows->each(fn (Media $row) => Storage::assertExists($row->path));
});

test('a batch downloads a shared url once and gives each of three destinations its own copy', function () {
    inlineMediaFakeImage();

    $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), [
        'status' => 'draft',
        'media' => [['url' => 'https://93.184.216.34/photo.png']],
        'destinations' => inlineMediaDestinations($this),
    ])->assertCreated();

    Http::assertSentCount(1);
    expect(Media::query()->whereNotNull('post_id')->get()->pluck('path')->unique())->toHaveCount(3);
});

test('the same url in a shared list and a destination override is downloaded once per call', function () {
    inlineMediaFakeImage();
    $destinations = inlineMediaDestinations($this);
    $destinations[0]['media'] = [['url' => 'https://93.184.216.34/photo.png']];

    $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), [
        'status' => 'draft',
        'media' => [['url' => 'https://93.184.216.34/photo.png']],
        'destinations' => $destinations,
    ])->assertCreated();

    Http::assertSentCount(1);
    expect(Media::query()->whereNotNull('post_id')->count())->toBe(3);
});

test('a batch gives each destination its own alt text and the shared one where it sets none', function (string $entry) {
    inlineMediaFakeImage();
    $destinations = inlineMediaDestinations($this);
    $destinations[0]['media'] = [['url' => 'https://93.184.216.34/photo.png', 'alt' => 'First channel description']];
    $destinations[1]['media'] = [['url' => 'https://93.184.216.34/photo.png']];
    $payload = [
        'status' => 'draft',
        'media' => [['url' => 'https://93.184.216.34/photo.png', 'alt' => 'Shared description']],
        'destinations' => $destinations,
    ];

    match ($entry) {
        'rest' => $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), $payload)->assertCreated(),
        'mcp' => TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, $payload)->assertOk(),
    };

    $altText = fn (SocialAccount $channel): ?string => data_get(Post::query()
        ->whereHas('postPlatforms', fn ($query) => $query->where('social_account_id', $channel->id))
        ->sole()->media, '0.meta.alt_text');

    expect($altText($this->channels[0]))->toBe('First channel description')
        ->and($altText($this->channels[1]))->toBe('Shared description')
        ->and($altText($this->channels[2]))->toBe('Shared description');
})->with(['rest', 'mcp']);

test('an id of another posts media is copied and the other post keeps its file', function () {
    $other = inlineMediaPost($this);
    $source = Media::factory()->stored()->ownedByPost($other)->create();
    Storage::put($source->path, 'bytes');

    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'content' => 'Copy',
        'media' => [['id' => $source->id]],
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']],
    ])->assertCreated();

    $copy = Post::query()->whereKeyNot($other->id)->sole()->ownedMedia()->sole();

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->path)->not->toBe($source->path)
        ->and($source->fresh()->post_id)->toBe($other->id);
    Storage::assertExists([$source->path, $copy->path]);
});

test('an item with two references or the old snapshot keys fails the shape rule', function (array $item) {
    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'content' => 'Shape',
        'media' => [$item],
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['media.0' => __('posts.errors.media_item_shape')]);
})->with([
    'id and url' => [['id' => '6f3c0a4e-0000-4000-8000-000000000000', 'url' => 'https://93.184.216.34/a.png']],
    'token and id' => [['upload_token' => '6f3c0a4e-0000-4000-8000-000000000000', 'id' => '6f3c0a4e-0000-4000-8000-000000000001']],
    'snapshot only' => [['path' => 'medias/a.jpg', 'type' => 'image']],
    'empty' => [[]],
]);

test('an old snapshot with a url downloads that url instead of trusting the path', function () {
    inlineMediaFakeImage();

    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'content' => 'Snapshot',
        'media' => [['path' => 'medias/forged.jpg', 'url' => 'https://93.184.216.34/photo.png']],
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']],
    ])->assertCreated();

    Http::assertSentCount(1);
    expect(Post::query()->sole()->ownedMedia()->sole()->path)->not->toBe('medias/forged.jpg');
});

test('every create and update entry point, REST and MCP, enforces the same shape error', function (string $entry) {
    $post = inlineMediaPost($this);
    $bad = [['id' => '6f3c0a4e-0000-4000-8000-000000000000', 'url' => 'https://93.184.216.34/a.png']];
    $platforms = [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']];

    match ($entry) {
        'rest create' => $this->withHeaders($this->headers)->postJson(route('api.posts.store'), ['media' => $bad, 'platforms' => $platforms])->assertJsonValidationErrors(['media.0']),
        'rest batch' => $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), ['status' => 'draft', 'media' => $bad, 'destinations' => inlineMediaDestinations($this)])->assertJsonValidationErrors(['media.0']),
        'rest batch override' => $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), ['status' => 'draft', 'destinations' => [[...inlineMediaDestinations($this)[0], 'media' => $bad]]])->assertJsonValidationErrors(['destinations.0.media.0']),
        'rest update' => $this->withHeaders($this->headers)->putJson(route('api.posts.update', $post), ['status' => 'draft', 'media' => $bad])->assertJsonValidationErrors(['media.0']),
        'mcp create' => TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, ['media' => $bad, 'platforms' => $platforms])->assertHasErrors([__('posts.errors.media_item_shape')]),
        'mcp batch' => TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, ['status' => 'draft', 'media' => $bad, 'destinations' => inlineMediaDestinations($this)])->assertHasErrors([__('posts.errors.media_item_shape')]),
        'mcp update' => TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, 'media' => $bad])->assertHasErrors([__('posts.errors.media_item_shape')]),
    };

    expect(Media::query()->count())->toBe(0);
})->with(['rest create', 'rest batch', 'rest batch override', 'rest update', 'mcp create', 'mcp batch', 'mcp update']);

test('the mcp tools accept the three shapes', function () {
    inlineMediaFakeImage();
    $upload = inlineMediaUpload($this);
    $other = inlineMediaPost($this);
    $source = Media::factory()->stored()->ownedByPost($other)->create();
    Storage::put($source->path, 'bytes');

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'media' => [['upload_token' => $upload->upload_token], ['id' => $source->id], ['url' => 'https://93.184.216.34/photo.png', 'alt' => 'Alt']],
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']],
    ])->assertOk();

    $post = Post::query()->whereKeyNot($other->id)->sole();

    expect($post->ownedMedia)->toHaveCount(3)
        ->and(data_get($post->media, '2.meta.alt_text'))->toBe('Alt');
});

test('attaching an upload by REST and by MCP gives the same media item', function () {
    $restPost = inlineMediaPost($this);
    $mcpPost = inlineMediaPost($this);
    $restUpload = inlineMediaUpload($this);
    $mcpUpload = inlineMediaUpload($this);

    $rest = $this->withHeaders($this->headers)
        ->postJson(route('api.posts.attach-media-from-upload', $restPost), ['upload_token' => $restUpload->upload_token, 'alt' => 'Same'])
        ->assertOk()->json('media.0');

    TryPostServer::actingAs($this->user)->tool(AttachMediaFromUploadTool::class, [
        'post_id' => $mcpPost->id,
        'upload_token' => $mcpUpload->upload_token,
        'alt' => 'Same',
    ])->assertOk();

    $mcp = data_get($mcpPost->fresh(), 'media.0');
    $ignore = ['id', 'path', 'url', 'created_at', 'original_filename', 'size'];

    expect(Arr::except($rest, $ignore))->toEqual(Arr::except($mcp, $ignore))
        ->and(data_get($rest, 'meta.alt_text'))->toBe('Same')
        ->and($restUpload->fresh()->post_id)->toBe($restPost->id);
});

test('attaching an unknown or spent token is refused on the token', function () {
    $post = inlineMediaPost($this);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.attach-media-from-upload', $post), ['upload_token' => (string) Str::uuid()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['upload_token' => __('posts.errors.media_expired')]);
});

function inlineMediaForeign(object $test): array
{
    $other = Workspace::factory()->create();
    $upload = Media::factory()->temporaryUpload($other)->stored()->create();
    $otherPost = Post::factory()->create(['workspace_id' => $other->id]);
    $owned = Media::factory()->stored()->ownedByPost($otherPost)->create();
    Storage::put($upload->path, 'bytes');
    Storage::put($owned->path, 'bytes');

    return [$upload, $owned];
}

test('another workspace\'s upload token or media id is rejected on every entry point and nothing is touched', function (string $entry, string $reference) {
    [$upload, $owned] = inlineMediaForeign($this);
    $post = inlineMediaPost($this);
    $item = $reference === 'token' ? ['upload_token' => $upload->upload_token] : ['id' => $owned->id];
    $key = $reference === 'token' ? 'upload_token' : 'id';
    $expected = $reference === 'token' ? __('posts.errors.media_expired') : __('validation.exists', ['attribute' => 'media']);
    $platforms = [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']];
    $before = Media::query()->count();
    $postsBefore = Post::query()->count();

    match ($entry) {
        'rest create' => $this->withHeaders($this->headers)->postJson(route('api.posts.store'), ['media' => [$item], 'platforms' => $platforms])->assertJsonValidationErrors(["media.0.{$key}" => $expected]),
        'rest batch' => $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), ['status' => 'draft', 'media' => [$item], 'destinations' => inlineMediaDestinations($this)])->assertJsonValidationErrors(["media.0.{$key}" => $expected]),
        'rest update' => $this->withHeaders($this->headers)->putJson(route('api.posts.update', $post), ['status' => 'draft', 'media' => [$item]])->assertJsonValidationErrors(["media.0.{$key}" => $expected]),
        'rest attach' => $this->withHeaders($this->headers)->postJson(route('api.posts.attach-media-from-upload', $post), ['upload_token' => $upload->upload_token])->assertJsonValidationErrors(['upload_token' => __('posts.errors.media_expired')]),
        'mcp create' => TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, ['media' => [$item], 'platforms' => $platforms])->assertHasErrors([$expected]),
        'mcp batch' => TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, ['status' => 'draft', 'media' => [$item], 'destinations' => inlineMediaDestinations($this)])->assertHasErrors([$expected]),
        'mcp update' => TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, ['post_id' => $post->id, 'media' => [$item]])->assertHasErrors([$expected]),
        'mcp attach' => TryPostServer::actingAs($this->user)->tool(AttachMediaFromUploadTool::class, ['post_id' => $post->id, 'upload_token' => $upload->upload_token])->assertHasErrors([__('posts.errors.media_expired')]),
    };

    expect(Media::query()->count())->toBe($before)
        ->and(Post::query()->count())->toBe($postsBefore)
        ->and($upload->fresh()->upload_token)->toBe($upload->upload_token)
        ->and($upload->fresh()->post_id)->toBeNull()
        ->and($owned->fresh()->path)->toBe($owned->path)
        ->and($post->fresh()->ownedMedia)->toBeEmpty();
    Storage::assertExists([$upload->path, $owned->path]);
})->with([
    'rest create token' => ['rest create', 'token'],
    'rest create id' => ['rest create', 'id'],
    'rest batch token' => ['rest batch', 'token'],
    'rest batch id' => ['rest batch', 'id'],
    'rest update token' => ['rest update', 'token'],
    'rest update id' => ['rest update', 'id'],
    'rest attach token' => ['rest attach', 'token'],
    'mcp create token' => ['mcp create', 'token'],
    'mcp create id' => ['mcp create', 'id'],
    'mcp batch token' => ['mcp batch', 'token'],
    'mcp batch id' => ['mcp batch', 'id'],
    'mcp update token' => ['mcp update', 'token'],
    'mcp update id' => ['mcp update', 'id'],
    'mcp attach token' => ['mcp attach', 'token'],
]);

test('a token consumed by a concurrent save between hosting and saving fails with media_expired', function () {
    $upload = inlineMediaUpload($this);
    $winner = inlineMediaPost($this);

    Post::creating(function () use ($upload, $winner): void {
        DB::table('medias')->where('id', $upload->id)->update([
            'post_id' => $winner->id,
            'collection' => Media::COLLECTION_MEDIA,
            'mediable_type' => null,
            'mediable_id' => null,
            'upload_token' => null,
        ]);
    });

    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'content' => 'Loser',
        'media' => [['upload_token' => $upload->upload_token]],
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['media.0' => __('posts.errors.media_expired')]);

    expect(Post::query()->where('content', 'Loser')->exists())->toBeFalse()
        ->and(Media::query()->count())->toBe(1);
});

test('a media object instead of a list is refused', function () {
    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'media' => ['b' => ['url' => 'https://93.184.216.34/a.png'], 'a' => ['url' => 'https://93.184.216.34/b.png']],
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => 'linkedin_post']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['media']);
});

test('a failing item deletes the urls the request already downloaded', function () {
    Http::fake([
        '93.184.216.34/good.png' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        '93.184.216.34/missing.png' => Http::response(null, 404),
    ]);
    $destinations = inlineMediaDestinations($this);
    $destinations[1]['media'] = [['url' => 'https://93.184.216.34/missing.png']];

    $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), [
        'status' => 'draft',
        'media' => [['url' => 'https://93.184.216.34/good.png']],
        'destinations' => $destinations,
    ])->assertUnprocessable()->assertJsonValidationErrors(['destinations.1.media.0.url']);

    expect(Media::query()->count())->toBe(0)
        ->and(Post::query()->count())->toBe(0);
});

test('the alt shorthand is kept for images and ignored for other types', function () {
    $video = Media::factory()->video()->temporaryUpload($this->workspace)->stored()->create();
    Storage::put($video->path, 'bytes');
    $image = inlineMediaUpload($this);

    $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), [
        'status' => 'draft',
        'media' => [['upload_token' => $image->upload_token, 'alt' => 'A photo'], ['upload_token' => $video->upload_token, 'alt' => 'A clip']],
        'destinations' => [inlineMediaDestinations($this)[0]],
    ]);

    $media = Post::query()->sole()->media;

    expect(data_get($media, '0.meta.alt_text'))->toBe('A photo')
        ->and(data_get($media, '1.meta'))->not->toHaveKey('alt_text');
});

test('an upload from a role that cannot create posts is forbidden before the file is checked', function () {
    $requester = User::factory()->create();
    $this->workspace->members()->attach($requester->id, membershipPivot('approval'));
    $requester->update(['current_workspace_id' => $this->workspace->id]);
    $headers = ['Authorization' => 'Bearer '.passportToken($requester, $this->workspace), 'Accept' => 'application/json'];

    $this->withHeaders($headers)
        ->post(route('api.uploads.create'), ['media' => UploadedFile::fake()->createWithContent('run.exe', 'MZ')])
        ->assertForbidden();
});

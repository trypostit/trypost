<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\PublishMode;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Repurpose\ProcessRepurposeItem;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Post\MediaAttacher;
use App\Services\Repurpose\CaptionAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The set of `posts.media[*].id` (or `ideas.media[*].id`) equals the ids of
 * the rows the owner owns, after every write path. Later tasks append their
 * paths to the dataset.
 */
beforeEach(function () {
    Storage::fake(null, ['url' => 'https://cdn.example.com']);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
});

function ownedMediaInvariantUpload(Workspace $workspace): array
{
    $upload = Media::factory()->temporaryUpload($workspace)->create();
    Storage::put($upload->path, 'bytes');

    return MediaItem::fromMedia($upload)->toArray();
}

function ownedMediaInvariantToken(Workspace $workspace): string
{
    return Media::factory()->temporaryUpload($workspace)->stored()->create()->upload_token;
}

const OWNED_MEDIA_INVARIANT_URL = 'https://example.com/photo.png';

function ownedMediaInvariantPng(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/1x1.png'));
}

function ownedMediaInvariantFakeUrl(): void
{
    Http::fake([OWNED_MEDIA_INVARIANT_URL => Http::response(ownedMediaInvariantPng(), 200, ['Content-Type' => 'image/png'])]);
}

function ownedMediaInvariantHeaders(object $test): array
{
    return ['Authorization' => 'Bearer '.data_get(createApiTestToken(['workspace' => $test->workspace]), 'plain_token')];
}

function ownedMediaInvariantCreate(object $test, int $uploads = 2): Post
{
    $test->actingAs($test->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'Invariant',
        'media' => array_map(fn (): array => ownedMediaInvariantUpload($test->workspace), range(1, $uploads)),
        'destinations' => [[
            'social_account_id' => $test->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
        ]],
    ])->assertSessionHasNoErrors();

    return Post::query()->latest('id')->firstOrFail();
}

function ownedMediaInvariantIdea(object $test): Idea
{
    $test->actingAs($test->user)->post(route('app.create.ideas.store'), [
        'title' => 'Invariant',
        'media_ids' => [ownedMediaInvariantUpload($test->workspace)['id'], ownedMediaInvariantUpload($test->workspace)['id']],
    ])->assertSessionHasNoErrors();

    return Idea::query()->latest('id')->firstOrFail();
}

/**
 * @return array<string, Closure(object): (Post|Idea)>
 */
function ownedMediaInvariantWritePaths(): array
{
    return [
        'web create' => fn (object $test): Post => ownedMediaInvariantCreate($test),
        'web update' => function (object $test): Post {
            $post = ownedMediaInvariantCreate($test);

            $test->actingAs($test->user)->put(route('app.posts.update', $post), [
                'status' => 'draft',
                'content' => 'Updated',
                'media' => [$post->media[1], ownedMediaInvariantUpload($test->workspace)],
            ])->assertSessionHasNoErrors();

            return $post;
        },
        'duplicate' => function (object $test): Post {
            $post = ownedMediaInvariantCreate($test);

            $test->actingAs($test->user)->post(route('app.posts.duplicate', $post))->assertSessionHasNoErrors();

            return Post::query()->whereKeyNot($post->id)->sole();
        },
        'appendMedia' => function (object $test): Post {
            $post = ownedMediaInvariantCreate($test, 1);
            $post->appendMedia([ownedMediaInvariantUpload($test->workspace)]);

            return $post;
        },
        'api create' => function (object $test): Post {
            $test->withHeaders(ownedMediaInvariantHeaders($test))->postJson(route('api.posts.store'), [
                'content' => 'Invariant',
                'media' => [['upload_token' => ownedMediaInvariantToken($test->workspace)]],
                'platforms' => [['social_account_id' => $test->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
            ])->assertCreated();

            return Post::query()->sole();
        },
        'mcp create' => function (object $test): Post {
            TryPostServer::actingAs($test->user)->tool(CreatePostTool::class, [
                'content' => 'Invariant',
                'media' => [['upload_token' => ownedMediaInvariantToken($test->workspace)]],
                'platforms' => [['social_account_id' => $test->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
            ])->assertOk();

            return Post::query()->sole();
        },
        'api batch create' => function (object $test): Post {
            $test->withHeaders(ownedMediaInvariantHeaders($test))->postJson(route('api.posts.batch.store'), [
                'status' => 'draft',
                'media' => [['upload_token' => ownedMediaInvariantToken($test->workspace)]],
                'destinations' => [['social_account_id' => $test->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
            ])->assertCreated();

            return Post::query()->sole();
        },
        'api update' => function (object $test): Post {
            $post = ownedMediaInvariantCreate($test);

            $test->withHeaders(ownedMediaInvariantHeaders($test))->putJson(route('api.posts.update', $post), [
                'status' => 'draft',
                'media' => [['id' => $post->media[1]['id']], ['upload_token' => ownedMediaInvariantToken($test->workspace)]],
            ])->assertOk();

            return $post;
        },
        'api attach from upload' => function (object $test): Post {
            $post = ownedMediaInvariantCreate($test, 1);

            $test->withHeaders(ownedMediaInvariantHeaders($test))->postJson(route('api.posts.attach-media-from-upload', $post), [
                'upload_token' => ownedMediaInvariantToken($test->workspace),
            ])->assertOk();

            return $post;
        },
        'api store media' => function (object $test): Post {
            $post = ownedMediaInvariantCreate($test, 1);

            $test->withHeaders([...ownedMediaInvariantHeaders($test), 'Accept' => 'application/json'])->post(route('api.posts.store-media', $post), [
                'media' => UploadedFile::fake()->createWithContent('photo.png', ownedMediaInvariantPng()),
            ])->assertOk();

            return $post;
        },
        'api attach from url' => function (object $test): Post {
            ownedMediaInvariantFakeUrl();
            $post = ownedMediaInvariantCreate($test, 1);

            $test->withHeaders(ownedMediaInvariantHeaders($test))->postJson(route('api.posts.attach-media-from-url', $post), [
                'urls' => [['url' => OWNED_MEDIA_INVARIANT_URL]],
            ])->assertOk()->assertJsonPath('attached_count', 1);

            return $post;
        },
        'mcp attach from url' => function (object $test): Post {
            ownedMediaInvariantFakeUrl();
            $post = ownedMediaInvariantCreate($test, 1);

            TryPostServer::actingAs($test->user)->tool(AttachMediaFromUrlTool::class, [
                'post_id' => $post->id,
                'urls' => [['url' => OWNED_MEDIA_INVARIANT_URL]],
            ])->assertOk();

            return $post;
        },
        'mcp create posts' => function (object $test): Post {
            TryPostServer::actingAs($test->user)->tool(CreatePostsTool::class, [
                'status' => 'draft',
                'media' => [['upload_token' => ownedMediaInvariantToken($test->workspace)]],
                'destinations' => [['social_account_id' => $test->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
            ])->assertOk();

            return Post::query()->sole();
        },
        'mcp update' => function (object $test): Post {
            $post = ownedMediaInvariantCreate($test);

            TryPostServer::actingAs($test->user)->tool(UpdatePostTool::class, [
                'post_id' => $post->id,
                'media' => [['id' => $post->media[1]['id']], ['upload_token' => ownedMediaInvariantToken($test->workspace)]],
            ])->assertOk();

            return $post;
        },
        'mcp attach from upload' => function (object $test): Post {
            $post = ownedMediaInvariantCreate($test, 1);

            TryPostServer::actingAs($test->user)->tool(AttachMediaFromUploadTool::class, [
                'post_id' => $post->id,
                'upload_token' => ownedMediaInvariantToken($test->workspace),
            ])->assertOk();

            return $post;
        },
        'repurpose' => function (object $test): Post {
            Http::fake(['https://93.184.216.34/v.mp4' => Http::response(file_get_contents(base_path('tests/fixtures/sample.mp4')), 200, ['Content-Type' => 'video/mp4'])]);
            $source = SocialAccount::factory()->for($test->workspace)->create(['platform' => Platform::Instagram]);
            $destinations = collect([Platform::YouTube, Platform::YouTube])
                ->map(fn (Platform $platform): array => [
                    'social_account_id' => SocialAccount::factory()->for($test->workspace)->create(['platform' => $platform])->id,
                    'content_type' => ContentType::YouTubeShort->value,
                    'meta' => [],
                ])->all();
            $repurpose = Repurpose::factory()->active()->create([
                'workspace_id' => $test->workspace->id,
                'source_social_account_id' => $source->id,
                'destinations' => $destinations,
                'publish_mode' => PublishMode::Draft,
            ]);
            $item = RepurposeItem::factory()->for($repurpose)->create();

            (new ProcessRepurposeItem($item, 'https://93.184.216.34/v.mp4', 'Caption'))
                ->handle(app(MediaAttacher::class), app(CaptionAdapter::class));

            expect(Post::query()->where('repurpose_item_id', $item->id)->count())->toBe(2);

            return Post::query()->where('repurpose_item_id', $item->id)->latest('id')->firstOrFail();
        },
        'idea create' => fn (object $test): Idea => ownedMediaInvariantIdea($test),
        'idea update' => function (object $test): Idea {
            $idea = ownedMediaInvariantIdea($test);

            $test->actingAs($test->user)->put(route('app.create.ideas.update', $idea), [
                'media_ids' => [$idea->media[1]['id'], ownedMediaInvariantUpload($test->workspace)['id']],
            ])->assertSessionHasNoErrors();

            return $idea;
        },
        'feed item to idea' => function (object $test): Idea {
            ownedMediaInvariantFakeUrl();
            $feed = RssFeed::factory()->create(['workspace_id' => $test->workspace->id]);
            $item = RssFeedItem::factory()->for($feed, 'feed')->create(['url' => 'https://93.184.216.34/post', 'image_url' => OWNED_MEDIA_INVARIANT_URL]);

            $test->actingAs($test->user)->post(route('app.create.feed-items.idea', $item))->assertSessionHasNoErrors();

            return Idea::query()->sole();
        },
        'idea duplicate' => function (object $test): Idea {
            $idea = ownedMediaInvariantIdea($test);

            $test->actingAs($test->user)->post(route('app.create.ideas.duplicate', $idea))->assertSessionHasNoErrors();

            return Idea::query()->whereKeyNot($idea->id)->sole();
        },
    ];
}

test('the media json ids equal the rows the owner owns', function (string $path) {
    $owner = ownedMediaInvariantWritePaths()[$path]($this)->fresh();

    expect(collect($owner->media)->pluck('id')->all())->toEqualCanonicalizing($owner->ownedMedia->pluck('id')->all())
        ->and($owner->media)->not->toBeEmpty();
})->with(array_keys(ownedMediaInvariantWritePaths()));

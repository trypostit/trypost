<?php

declare(strict_types=1);

use App\Actions\Media\AuditMedia;
use App\Actions\Media\SyncOwnedMedia;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\DeletePost;
use App\Actions\Post\DuplicatePost;
use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    Queue::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->post = Post::factory()->forAccount($account, ContentType::InstagramReel)->create([
        'user_id' => $this->user->id,
        'status' => Status::Draft,
        'scheduled_at' => null,
    ]);
    $this->video = Media::factory()->video()->ownedByPost($this->post)->stored()->create(['meta' => ['duration' => 10]]);
    $this->post->update(['media' => [MediaItem::fromMedia($this->video)->toArray()]]);
});

/**
 * @param  array<string, mixed>|null  $cover
 * @return array<string, mixed>
 */
function videoCoverImageItem(Media $video, ?array $cover): array
{
    $item = MediaItem::fromMedia($video)->toArray();

    if ($cover === null) {
        return $item;
    }

    return [...$item, 'meta' => [...$item['meta'], 'cover' => $cover]];
}

/**
 * @param  array<int, array<string, mixed>>  $media
 */
function updateVideoCoverImageOnWeb(object $test, array $media): mixed
{
    return $test->actingAs($test->user)->put(route('app.posts.update', $test->post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => $media,
    ]);
}

/**
 * @return array<string, string>
 */
function videoCoverImageApiHeaders(Workspace $workspace): array
{
    return ['Authorization' => 'Bearer '.createApiTestToken(['workspace' => $workspace])['plain_token']];
}

test('the web composer adopts an uploaded image as the video cover', function () {
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    updateVideoCoverImageOnWeb($this, [videoCoverImageItem($this->video, MediaItem::fromMedia($upload)->toArray())])
        ->assertSessionHasNoErrors();

    $cover = data_get($this->post->fresh()->media, '0.meta.cover');

    expect(data_get($cover, 'id'))->toBe($upload->id)
        ->and(data_get($cover, 'url'))->toBe($upload->fresh()->url)
        ->and($cover)->not->toHaveKey('upload_token')
        ->and($upload->fresh()->post_id)->toBe($this->post->id)
        ->and($upload->fresh()->upload_token)->toBeNull();
});

test('removing the cover deletes its row and keeps the video', function () {
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    updateVideoCoverImageOnWeb($this, [videoCoverImageItem($this->video, MediaItem::fromMedia($upload)->toArray())]);

    updateVideoCoverImageOnWeb($this, [videoCoverImageItem($this->video, null)])->assertSessionHasNoErrors();

    expect(data_get($this->post->fresh()->media, '0.meta.cover'))->toBeNull()
        ->and(Media::query()->whereKey($upload->id)->exists())->toBeFalse()
        ->and(Media::query()->whereKey($this->video->id)->exists())->toBeTrue();
});

test('the web composer keeps a stored cover when the video item is sent again', function () {
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    updateVideoCoverImageOnWeb($this, [videoCoverImageItem($this->video, MediaItem::fromMedia($upload)->toArray())]);
    $stored = $this->post->fresh()->media;

    updateVideoCoverImageOnWeb($this, $stored)->assertSessionHasNoErrors();

    expect(data_get($this->post->fresh()->media, '0.meta.cover.id'))->toBe($upload->id)
        ->and(Media::query()->whereKey($upload->id)->exists())->toBeTrue();
});

test('a cover that is not a still image is refused', function (Closure $makeCover) {
    $cover = $makeCover($this->workspace);

    updateVideoCoverImageOnWeb($this, [videoCoverImageItem($this->video, MediaItem::fromMedia($cover)->toArray())])
        ->assertSessionHasErrors(['media.0.meta.cover' => 'The video cover must be a JPEG, PNG or WebP image.']);

    expect(data_get($this->post->fresh()->media, '0.meta.cover'))->toBeNull()
        ->and($cover->fresh()->post_id)->toBeNull();
})->with([
    'a video' => [fn (Workspace $workspace): Media => Media::factory()->video()->stored()->temporaryUpload($workspace)->create()],
    'a gif' => [fn (Workspace $workspace): Media => Media::factory()->stored()->temporaryUpload($workspace)->create(['mime_type' => 'image/gif', 'path' => 'media/cover.gif'])],
]);

test('a cover without a file reference is refused', function (mixed $cover) {
    $this->withHeaders(videoCoverImageApiHeaders($this->workspace))->putJson(route('api.posts.update', $this->post), [
        'status' => 'draft',
        'media' => [['id' => $this->video->id, 'meta' => ['cover' => $cover]]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['media.0.meta' => 'Choose an image for the video cover.']);
})->with([
    'a string' => ['https://example.com/cover.jpg'],
    'an empty object' => [['alt_text' => 'cover']],
]);

test('the rest api takes the cover as an upload token', function () {
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    $this->withHeaders(videoCoverImageApiHeaders($this->workspace))->putJson(route('api.posts.update', $this->post), [
        'status' => 'draft',
        'media' => [['id' => $this->video->id, 'meta' => ['cover' => ['upload_token' => $upload->upload_token]]]],
    ])->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta.cover.id'))->toBe($upload->id)
        ->and($upload->fresh()->post_id)->toBe($this->post->id);
});

test('mcp takes the cover as a media id of the workspace and copies it', function () {
    $otherPost = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $otherPostImage = Media::factory()->stored()->ownedByPost($otherPost)->create();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $this->post->id,
        'media' => [['id' => $this->video->id, 'meta' => ['cover' => ['id' => $otherPostImage->id]]]],
    ])->assertHasNoErrors();

    $coverId = data_get($this->post->fresh()->media, '0.meta.cover.id');

    expect($coverId)->not->toBe($otherPostImage->id)
        ->and(Media::query()->find($coverId)->post_id)->toBe($this->post->id)
        ->and($otherPostImage->fresh()->post_id)->toBe($otherPost->id);
});

test('a duplicate copies the cover and deleting the post deletes it', function () {
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    updateVideoCoverImageOnWeb($this, [videoCoverImageItem($this->video, MediaItem::fromMedia($upload)->toArray())]);

    $copy = DuplicatePost::execute($this->post->fresh(), $this->user);
    $copiedId = data_get($copy->fresh()->media, '0.meta.cover.id');

    expect($copiedId)->not->toBe($upload->id)
        ->and(Media::query()->find($copiedId)->post_id)->toBe($copy->id);

    DeletePost::execute($this->post->fresh());

    expect(Media::query()->whereKey($upload->id)->exists())->toBeFalse()
        ->and(Media::query()->whereKey($copiedId)->exists())->toBeTrue();
});

test('the media audit counts a cover as referenced by its post', function () {
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    updateVideoCoverImageOnWeb($this, [videoCoverImageItem($this->video, MediaItem::fromMedia($upload)->toArray())]);

    expect(collect(AuditMedia::execute(checkMissingFiles: false)['json_drift'])->pluck('media_id'))->not->toContain($upload->id);
});

test('the media item reads its cover image', function () {
    $cover = MediaItem::fromArray(['meta' => ['cover' => ['id' => 'c', 'path' => 'media/c.jpg', 'url' => 'https://cdn.test/c.jpg', 'mime_type' => 'image/jpeg']]])->cover();

    expect($cover?->url)->toBe('https://cdn.test/c.jpg')
        ->and($cover?->isStillImage())->toBeTrue()
        ->and(MediaItem::fromArray(['meta' => []])->cover())->toBeNull();
});

test('a post on a network that only takes a frame keeps the frame and drops the cover image', function () {
    $tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($tiktok, ContentType::TikTokVideo)->create([
        'user_id' => $this->user->id,
        'status' => Status::Draft,
        'scheduled_at' => null,
    ]);
    $video = Media::factory()->video()->ownedByPost($post)->stored()->create(['meta' => ['duration' => 10]]);
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    $this->withHeaders(videoCoverImageApiHeaders($this->workspace))->putJson(route('api.posts.update', $post), [
        'status' => 'draft',
        'media' => [['id' => $video->id, 'meta' => ['cover_offset_ms' => 1500, 'cover_source' => 'image', 'cover' => ['upload_token' => $upload->upload_token]]]],
    ])->assertOk();

    expect(data_get($post->fresh()->media, '0.meta.cover'))->toBeNull()
        ->and(data_get($post->fresh()->media, '0.meta.cover_source'))->toBeNull()
        ->and(data_get($post->fresh()->media, '0.meta.cover_offset_ms'))->toBe(1500)
        ->and($upload->fresh()->post_id)->toBeNull();
});

test('the cover source is stored with the cover and must be a frame or an image', function () {
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    $this->withHeaders(videoCoverImageApiHeaders($this->workspace))->putJson(route('api.posts.update', $this->post), [
        'status' => 'draft',
        'media' => [['id' => $this->video->id, 'meta' => ['cover_source' => 'sticker', 'cover' => ['upload_token' => $upload->upload_token]]]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['media.0.meta']);

    $this->withHeaders(videoCoverImageApiHeaders($this->workspace))->putJson(route('api.posts.update', $this->post), [
        'status' => 'draft',
        'media' => [['id' => $this->video->id, 'meta' => ['cover_source' => 'image', 'cover' => ['upload_token' => $upload->upload_token]]]],
    ])->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta.cover_source'))->toBe('image');
});

test('a video saved without its media row drops its image source once the cover goes', function () {
    $item = [
        'id' => 'legacy-video',
        'path' => 'medias/legacy.mp4',
        'url' => 'https://cdn.example.com/medias/legacy.mp4',
        'type' => 'video',
        'mime_type' => 'video/mp4',
        'meta' => ['duration' => 10, 'cover_source' => 'image', 'cover_offset_ms' => 0],
    ];
    $this->post->update(['media' => [$item]]);

    updateVideoCoverImageOnWeb($this, [[...$item, 'meta' => ['duration' => 10, 'cover_offset_ms' => 2000]]])->assertRedirect();

    expect(data_get($this->post->fresh()->media, '0.meta.cover_source'))->toBeNull()
        ->and(data_get($this->post->fresh()->media, '0.meta.cover_offset_ms'))->toBe(2000);
});

test('a thread reply video drops a cover image sent with it', function () {
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    $replyVideo = Media::factory()->video()->stored()->temporaryUpload($this->workspace)->create();

    $post = CreatePosts::execute($this->workspace, $this->user, [
        'status' => 'draft',
        'content' => 'Root',
        'destinations' => [[
            'social_account_id' => $x->id,
            'content_type' => ContentType::XPost->value,
            'meta' => ['thread_replies' => [['text' => 'Two', 'media' => [[
                ...MediaItem::fromMedia($replyVideo)->toArray(),
                'meta' => ['cover' => MediaItem::fromMedia($upload)->toArray()],
            ]]]]],
        ]],
    ])->sole();

    expect(data_get($post->fresh()->meta, 'thread_replies.0.media.0.meta.cover'))->toBeNull()
        ->and($upload->fresh()->post_id)->toBeNull()
        ->and($replyVideo->fresh()->post_id)->toBe($post->id);
});

test('an idea keeps the cover image for the post it becomes', function () {
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $video = Media::factory()->video()->stored()->temporaryUpload($this->workspace)->create();
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($idea, [videoCoverImageItem($video, MediaItem::fromMedia($upload)->toArray())], $batch));

    expect(data_get($idea->fresh()->media, '0.meta.cover.id'))->toBe($upload->id)
        ->and($upload->fresh()->idea_id)->toBe($idea->id);
});

test('turning a reel into a feed post drops its cover image and deletes the row', function () {
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    updateVideoCoverImageOnWeb($this, [videoCoverImageItem($this->video, MediaItem::fromMedia($upload)->toArray())]);
    $stored = $this->post->fresh()->media;

    $this->actingAs($this->user)->put(route('app.posts.update', $this->post), [
        'status' => 'draft',
        'content' => 'hi',
        'content_type' => ContentType::InstagramFeed->value,
        'media' => $stored,
    ])->assertSessionHasNoErrors();

    expect($this->post->fresh()->content_type)->toBe(ContentType::InstagramFeed)
        ->and(data_get($this->post->fresh()->media, '0.meta.cover'))->toBeNull()
        ->and(Media::query()->whereKey($upload->id)->exists())->toBeFalse();
});

test('a cover takes its video\'s place in the order instead of one of its own', function () {
    $second = Media::factory()->video()->ownedByPost($this->post)->stored()->create(['meta' => ['duration' => 10]]);
    $upload = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    updateVideoCoverImageOnWeb($this, [
        videoCoverImageItem($this->video, MediaItem::fromMedia($upload)->toArray()),
        MediaItem::fromMedia($second)->toArray(),
    ])->assertSessionHasNoErrors();

    expect($this->video->fresh()->order)->toBe(0)
        ->and($upload->fresh()->order)->toBe(0)
        ->and($second->fresh()->order)->toBe(1);
});

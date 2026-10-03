<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
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
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => Status::Draft,
        'scheduled_at' => null,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => ContentType::InstagramReel,
        'enabled' => true,
    ]);
    $this->video = Media::factory()->video()->ownedByPost($this->post)->stored()->create(['meta' => ['duration' => 10]]);
    $this->post->update(['media' => [videoCoverOffsetItem($this->video, [])]]);
});

/**
 * @param  array<string, mixed>  $edits
 * @return array<string, mixed>
 */
function videoCoverOffsetItem(Media $video, array $edits): array
{
    return [
        'id' => $video->id,
        'path' => $video->path,
        'url' => $video->url,
        'type' => 'video',
        'mime_type' => 'video/mp4',
        'meta' => ['duration' => 10, ...$edits],
    ];
}

/**
 * @return array<string, string>
 */
function videoCoverOffsetApiHeaders(Workspace $workspace): array
{
    return ['Authorization' => 'Bearer '.createApiTestToken(['workspace' => $workspace])['plain_token']];
}

test('the web composer stores the video cover offset on the media item', function () {
    $this->actingAs($this->user)->put(route('app.posts.update', $this->post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [videoCoverOffsetItem($this->video, ['cover_offset_ms' => 1500])],
    ])->assertSessionHasNoErrors();

    expect($this->post->fresh()->media[0]['meta'])->toEqual(['duration' => 10, 'cover_offset_ms' => 1500]);
});

test('the rest api stores the video cover offset on the media item', function () {
    $this->withHeaders(videoCoverOffsetApiHeaders($this->workspace))->putJson(route('api.posts.update', $this->post), [
        'status' => 'draft',
        'media' => [['id' => $this->video->id, 'meta' => ['cover_offset_ms' => 1500]]],
    ])->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta.cover_offset_ms'))->toBe(1500);
});

test('a numeric string or float cover offset is stored as an integer', function (string|float $offset) {
    $this->withHeaders(videoCoverOffsetApiHeaders($this->workspace))->putJson(route('api.posts.update', $this->post), [
        'status' => 'draft',
        'media' => [['id' => $this->video->id, 'meta' => ['cover_offset_ms' => $offset]]],
    ])->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta.cover_offset_ms'))->toBe(1500);
})->with(['string' => ['1500'], 'float' => [1500.0]]);

test('mcp stores the video cover offset on the media item', function () {
    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $this->post->id,
        'media' => [['id' => $this->video->id, 'meta' => ['cover_offset_ms' => 1500]]],
    ])->assertHasNoErrors();

    expect(data_get($this->post->fresh()->media, '0.meta.cover_offset_ms'))->toBe(1500);
});

test('the web composer rejects a negative or past-the-end cover offset', function (int $offset, string $message) {
    $this->actingAs($this->user)->put(route('app.posts.update', $this->post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [videoCoverOffsetItem($this->video, ['cover_offset_ms' => $offset])],
    ])->assertSessionHasErrors(['media.0.meta' => $message]);

    expect(data_get($this->post->fresh()->media, '0.meta.cover_offset_ms'))->toBeNull();
})->with([
    'negative' => [-1, 'The video cover time must be 0 ms or more.'],
    'past the end of a 10 s video' => [99999999, 'The video cover time must be 10000 ms or less.'],
]);

test('the rest api rejects a negative or past-the-end cover offset', function (int $offset, string $key, string $message) {
    $this->withHeaders(videoCoverOffsetApiHeaders($this->workspace))->putJson(route('api.posts.update', $this->post), [
        'status' => 'draft',
        'media' => [['id' => $this->video->id, 'meta' => ['cover_offset_ms' => $offset]]],
    ])->assertUnprocessable()->assertJsonValidationErrors([$key => $message]);

    expect(data_get($this->post->fresh()->media, '0.meta.cover_offset_ms'))->toBeNull();
})->with([
    'negative' => [-1, 'media.0.meta', '0 ms or more'],
    'past the end of a 10 s video' => [99999999, 'destinations.0.media.0.meta', '10000 ms or less'],
]);

test('mcp rejects a negative or past-the-end cover offset', function (int $offset, string $message) {
    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $this->post->id,
        'media' => [['id' => $this->video->id, 'meta' => ['cover_offset_ms' => $offset]]],
    ])->assertHasErrors([$message]);

    expect(data_get($this->post->fresh()->media, '0.meta.cover_offset_ms'))->toBeNull();
})->with([
    'negative' => [-1, 'The video cover time must be 0 ms or more.'],
    'past the end of a 10 s video' => [99999999, 'The video cover time must be 10000 ms or less.'],
]);

test('a forged duration cannot stretch the measured one', function () {
    $this->actingAs($this->user)->put(route('app.posts.update', $this->post), [
        'status' => 'draft',
        'content' => 'hi',
        'media' => [[...videoCoverOffsetItem($this->video, ['cover_offset_ms' => 60000]), 'meta' => ['duration' => 999, 'cover_offset_ms' => 60000]]],
    ])->assertSessionHasErrors(['destinations.0.media.0.meta' => 'The video cover time must be 10000 ms or less.']);

    expect(data_get($this->post->fresh()->media, '0.meta.cover_offset_ms'))->toBeNull();
});

test('the media item reads its cover offset', function () {
    expect(MediaItem::fromArray(['meta' => ['cover_offset_ms' => 1500]])->coverOffsetMs())->toBe(1500)
        ->and(MediaItem::fromArray(['meta' => []])->coverOffsetMs())->toBeNull();
});

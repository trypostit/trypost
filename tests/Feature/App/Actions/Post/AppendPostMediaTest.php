<?php

declare(strict_types=1);

use App\Actions\Post\AppendPostMedia;
use App\Actions\Post\CreatePosts;
use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Queue::fake();
    Storage::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

function appendPostMediaItem(Workspace $workspace, bool $video = false): array
{
    $factory = Media::factory()->stored();
    $media = ($video ? $factory->video() : $factory)->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
        'collection' => 'uploads',
        'upload_token' => (string) Str::uuid(),
    ]);

    return MediaItem::fromMedia($media)->toArray();
}

test('media is not appended to a post that is publishing or settled', function (PostStatus $status) {
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $post = CreatePosts::execute($this->workspace, $this->user, [
        'status' => 'draft',
        'content' => 'Caption',
        'destinations' => [['social_account_id' => $channel->id]],
    ])->sole();
    Post::query()->whereKey($post->id)->update(['status' => $status]);

    expect(fn () => AppendPostMedia::execute($post, [appendPostMediaItem($this->workspace)], $this->user))
        ->toThrow(ValidationException::class);

    expect($post->fresh()->media ?? [])->toHaveCount(0)
        ->and($post->fresh()->status)->toBe($status);
})->with([PostStatus::Publishing, PostStatus::Published, PostStatus::Failed]);

test('appending media derives the pinterest and tiktok type again', function (string $factory, bool $video, ContentType $expected) {
    $channel = SocialAccount::factory()->{$factory}()->create(['workspace_id' => $this->workspace->id]);
    $post = CreatePosts::execute($this->workspace, $this->user, [
        'status' => 'draft',
        'content' => 'Caption',
        'media' => [appendPostMediaItem($this->workspace)],
        'destinations' => [['social_account_id' => $channel->id, 'meta' => ['board_id' => 'board-1']]],
    ])->sole();

    AppendPostMedia::execute($post, [appendPostMediaItem($this->workspace, $video)], $this->user);

    expect($post->fresh()->content_type)->toBe($expected);
})->with([
    'pinterest second image' => ['pinterest', false, ContentType::PinterestCarousel],
    'pinterest video' => ['pinterest', true, ContentType::PinterestVideoPin],
    'tiktok video' => ['tiktok', true, ContentType::TikTokVideo],
]);

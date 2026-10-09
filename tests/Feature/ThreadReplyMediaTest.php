<?php

declare(strict_types=1);

use App\Actions\Post\AppendPostMedia;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\DeletePost;
use App\Actions\Post\DuplicatePost;
use App\Actions\Post\UpdatePost;
use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostCompositionValidator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
});

function threadReplyMediaPost(object $test, array $replies, array $media = [], string $status = 'draft'): Post
{
    return CreatePosts::execute($test->workspace, $test->user, [
        'status' => $status,
        'content' => 'Root',
        'media' => $media,
        'scheduled_at' => $status === 'scheduled' ? now()->addDay()->toIso8601String() : null,
        'destinations' => [[
            'social_account_id' => $test->account->id,
            'content_type' => ContentType::XPost->value,
            'meta' => ['thread_replies' => $replies],
        ]],
    ])->sole();
}

test('a reply keeps its own media, owned by the post and apart from the root media', function () {
    $rootImage = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    $replyImage = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    $post = threadReplyMediaPost($this, [
        ['text' => 'Two', 'media' => [MediaItem::fromMedia($replyImage)->toArray()]],
        'Three',
    ], [MediaItem::fromMedia($rootImage)->toArray()], 'scheduled');

    $replies = $post->fresh()->meta['thread_replies'];

    expect(collect($post->fresh()->media)->pluck('id')->all())->toBe([$rootImage->id])
        ->and($replies[0]['text'])->toBe('Two')
        ->and(collect($replies[0]['media'])->pluck('id')->all())->toBe([$replyImage->id])
        ->and($replies[0]['media'][0]['url'])->toBe($replyImage->fresh()->url)
        ->and($replies[1])->toEqual(['text' => 'Three', 'media' => []])
        ->and($replyImage->fresh()->post_id)->toBe($post->id)
        ->and($post->ownedMedia()->pluck('id')->all())->toEqualCanonicalizing([$rootImage->id, $replyImage->id]);
});

test('a reply with only media is valid and an empty reply is not', function () {
    $image = Media::factory()->stored()->temporaryUpload($this->workspace)->create();

    $post = threadReplyMediaPost($this, [['text' => '', 'media' => [MediaItem::fromMedia($image)->toArray()]]], status: 'scheduled');

    expect($post->status)->toBe(PostStatus::Scheduled);

    expect(fn () => threadReplyMediaPost($this, [['text' => ' ', 'media' => []]], status: 'scheduled'))
        ->toThrow(ValidationException::class, __('posts.form.thread.reply_empty'));
});

test('a reply takes the media rules of a post on its network', function () {
    $images = Media::factory()->stored()->temporaryUpload($this->workspace)->count(5)->create();

    try {
        threadReplyMediaPost($this, [[
            'text' => 'Two',
            'media' => $images->map(fn (Media $image): array => MediaItem::fromMedia($image)->toArray())->all(),
        ]], status: 'scheduled');
        $this->fail('Five images on an X reply should not save.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('destinations.0.meta.thread_replies.0.media')
            ->and($e->errors()['destinations.0.meta.thread_replies.0.media'][0])->toBe(__('posts.form.warnings.max_files_exceeded', ['max' => 4, 'current' => 5]));
    }
});

test('editing a reply with a media that no longer exists fails on that reply item', function () {
    $post = threadReplyMediaPost($this, ['Two']);
    $image = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    $item = MediaItem::fromMedia($image)->toArray();
    $image->delete();

    try {
        UpdatePost::execute($this->workspace, $post->fresh(), [
            'status' => 'draft',
            'meta' => ['thread_replies' => [['text' => 'Two', 'media' => [$item]]]],
        ], $this->user);
        $this->fail('A reply media that was deleted should not save.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('meta.thread_replies.0.media.0');
    }
});

test('a reply media that no longer exists fails on that destination reply in a batch', function () {
    $image = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    $item = MediaItem::fromMedia($image)->toArray();
    $image->delete();

    try {
        threadReplyMediaPost($this, [['text' => 'Two', 'media' => [$item]]]);
        $this->fail('A reply media that was deleted should not save.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('destinations.0.meta.thread_replies.0.media.0');
    }
});

test('a single post keeps the reply media error under its own meta', function () {
    $image = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    $item = MediaItem::fromMedia($image)->toArray();
    $image->delete();

    try {
        PostCompositionValidator::forSinglePost(fn (): Post => threadReplyMediaPost($this, [['text' => 'Two', 'media' => [$item]]]));
        $this->fail('A reply media that was deleted should not save.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('meta.thread_replies.0.media.0');
    }
});

test('removing a reply media deletes its row and appending to the root keeps the reply media', function () {
    $replyImage = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    $post = threadReplyMediaPost($this, [['text' => 'Two', 'media' => [MediaItem::fromMedia($replyImage)->toArray()]]]);

    $rootImage = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    AppendPostMedia::execute($post->fresh(), [MediaItem::fromMedia($rootImage)->toArray()], $this->user);

    expect(Media::query()->whereKey($replyImage->id)->exists())->toBeTrue()
        ->and($post->fresh()->meta['thread_replies'][0]['media'][0]['id'])->toBe($replyImage->id);

    UpdatePost::execute($this->workspace, $post->fresh(), [
        'status' => PostStatus::Draft->value,
        'meta' => ['thread_replies' => [['text' => 'Two', 'media' => []]]],
    ]);

    expect(Media::query()->whereKey($replyImage->id)->exists())->toBeFalse()
        ->and(Media::query()->whereKey($rootImage->id)->exists())->toBeTrue();
});

test('a duplicate copies the reply media and deleting the post deletes it', function () {
    $replyImage = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
    $post = threadReplyMediaPost($this, [['text' => 'Two', 'media' => [MediaItem::fromMedia($replyImage)->toArray()]]]);

    $copy = DuplicatePost::execute($post->fresh(), $this->user);
    $copiedId = $copy->fresh()->meta['thread_replies'][0]['media'][0]['id'];

    expect($copiedId)->not->toBe($replyImage->id)
        ->and(Media::query()->find($copiedId)->post_id)->toBe($copy->id);

    DeletePost::execute($post->fresh());

    expect(Media::query()->whereKey($replyImage->id)->exists())->toBeFalse()
        ->and(Media::query()->whereKey($copiedId)->exists())->toBeTrue();
});

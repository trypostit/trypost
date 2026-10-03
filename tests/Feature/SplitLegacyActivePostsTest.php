<?php

declare(strict_types=1);

use App\Actions\Media\AdoptWorkspaceLibrary;
use App\Actions\Media\AuditMedia;
use App\Actions\Media\DeleteOwnedMedia;
use App\Actions\Post\UpdatePost;
use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Events\PostCreated;
use App\Events\PostStatusChanged;
use App\Models\AnalyticsPublication;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

test('split preserves IDs and clones labels, media and notes once', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => Status::Scheduled,
        'scheduled_at' => now()->addDay(),
        'content' => 'Legacy shared caption',
        'media' => [['id' => 'media-id', 'path' => 'posts/photo.jpg']],
    ]);
    $accounts = SocialAccount::factory()->count(3)->create(['workspace_id' => $workspace->id]);
    $first = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $accounts[0]->id]);
    $second = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $accounts[1]->id]);
    $disabled = PostPlatform::factory()->disabled()->create(['post_id' => $post->id, 'social_account_id' => $accounts[2]->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $post->labels()->attach($label);
    $comment = PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $user->id]);
    $older = PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'updated_at' => now()->subDays(3),
    ]);

    Event::fake([PostCreated::class]);
    $this->artisan('posts:split-legacy-active')->assertSuccessful();
    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    $clone = Post::whereKeyNot($post->id)->sole();
    expect(Post::count())->toBe(2)
        ->and($first->fresh()->post_id)->toBe($post->id)
        ->and($second->fresh()->post_id)->toBe($clone->id)
        ->and($disabled->fresh()->post_id)->toBe($post->id)
        ->and($clone->content)->toBe($post->content)
        ->and($clone->media)->toEqual($post->media)
        ->and($clone->scheduled_at->equalTo($post->scheduled_at))->toBeTrue()
        ->and($clone->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id])
        ->and($clone->notes()->count())->toBe(2)
        ->and($clone->notes()->where('body', $comment->body)->exists())->toBeTrue()
        ->and($clone->notes()->where('body', $older->body)->sole()->updated_at->equalTo($older->updated_at))->toBeTrue();
    Event::assertNotDispatched(PostCreated::class);
});

test('split leaves in-flight aggregates and settled ones with an unfinished target untouched', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id]);
    $publishing = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => Status::Publishing]);
    $unfinished = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => Status::PartiallyPublished]);
    foreach ($accounts as $account) {
        PostPlatform::factory()->create(['post_id' => $publishing->id, 'social_account_id' => $account->id]);
    }
    PostPlatform::factory()->published()->create(['post_id' => $unfinished->id, 'social_account_id' => $accounts[0]->id]);
    PostPlatform::factory()->create(['post_id' => $unfinished->id, 'social_account_id' => $accounts[1]->id, 'status' => PostPlatformStatus::PendingReview]);

    $this->artisan('posts:split-legacy-active')
        ->expectsOutputToContain('Left 1 settled post(s) with an unfinished target as they are.')
        ->assertSuccessful();

    expect(Post::count())->toBe(2)
        ->and(PostPlatform::count())->toBe(4)
        ->and($unfinished->fresh()->status)->toBe(Status::PartiallyPublished);
});

test('the original post remains editable after a split with a disabled target', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $accounts = SocialAccount::factory()->linkedin()->count(3)->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => Status::Draft,
        'content' => 'Original caption',
        'media' => [],
    ]);
    $first = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $accounts[0]->id]);
    $second = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $accounts[1]->id]);
    $disabled = PostPlatform::factory()->disabled()->create(['post_id' => $post->id, 'social_account_id' => $accounts[2]->id]);

    $this->artisan('posts:split-legacy-active')->assertSuccessful();
    UpdatePost::execute($workspace, $post->fresh(), [
        'status' => Status::Draft->value,
        'content' => 'Changed caption',
        'content_type' => $first->content_type->value,
    ]);

    expect($post->fresh()->content)->toBe('Changed caption')
        ->and($second->fresh()->post->content)->toBe('Original caption')
        ->and($disabled->fresh()->enabled)->toBeFalse();

    expect(fn () => UpdatePost::execute($workspace, $post->fresh(), [
        'platforms' => [['id' => $disabled->id]],
    ]))->toThrow(ValidationException::class);
    expect($disabled->fresh()->enabled)->toBeFalse();
});

function legacyAggregatePost(Workspace $workspace, User $user, Status $status, int $targets, array $attributes = []): Post
{
    $post = Post::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => $status,
        'scheduled_at' => $status === Status::Scheduled ? now()->addDay() : null,
    ], $attributes));

    foreach (SocialAccount::factory()->count($targets)->create(['workspace_id' => $workspace->id]) as $account) {
        PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
    }

    return $post;
}

test('split posts share one group and a re-run changes nothing', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = legacyAggregatePost($workspace, $user, Status::Scheduled, 3);
    $updatedAt = $post->fresh()->updated_at;

    $this->artisan('posts:split-legacy-active')
        ->expectsOutputToContain('Split 1 original posts and created 2 independent posts.')
        ->assertSuccessful();

    $groups = Post::query()->pluck('post_group_id', 'id');
    $groupId = $groups->get($post->id);

    expect($groups)->toHaveCount(3)
        ->and($groupId)->not->toBeNull()
        ->and($groups->unique()->values()->all())->toBe([$groupId])
        ->and($post->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();

    $this->artisan('posts:split-legacy-active')
        ->expectsOutputToContain('Split 0 original posts and created 0 independent posts.')
        ->expectsOutputToContain('Grouped 0 multi-target posts.')
        ->assertSuccessful();

    expect(Post::count())->toBe(3)
        ->and(Post::query()->pluck('post_group_id', 'id')->all())->toEqual($groups->all());
});

test('split reuses a group the original already has', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $groupId = '0190a3b2-0000-7000-8000-0000000000aa';
    legacyAggregatePost($workspace, $user, Status::Draft, 2, ['post_group_id' => $groupId]);

    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    expect(Post::query()->pluck('post_group_id')->unique()->all())->toBe([$groupId]);
});

test('unsplit multi-target history gets its own group and single-target posts stay ungrouped', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $published = legacyAggregatePost($workspace, $user, Status::Published, 2);
    $failed = legacyAggregatePost($workspace, $user, Status::Failed, 3);
    $partial = legacyAggregatePost($workspace, $user, Status::PartiallyPublished, 2);
    $single = legacyAggregatePost($workspace, $user, Status::Published, 1);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->id]);
    $alreadyGrouped = legacyAggregatePost($otherWorkspace, $user, Status::Published, 2, ['post_group_id' => '0190a3b2-0000-7000-8000-0000000000bb']);
    $otherSingle = legacyAggregatePost($otherWorkspace, $user, Status::Draft, 1);

    $this->artisan('posts:split-legacy-active')
        ->expectsOutputToContain('Grouped 3 multi-target posts.')
        ->assertSuccessful();

    $groups = collect([$published, $failed, $partial])->map(fn (Post $post) => $post->fresh()->post_group_id);

    expect($groups->filter()->unique())->toHaveCount(3)
        ->and(Post::count())->toBe(6)
        ->and($single->fresh()->post_group_id)->toBeNull()
        ->and($otherSingle->fresh()->post_group_id)->toBeNull()
        ->and($alreadyGrouped->fresh()->post_group_id)->toBe('0190a3b2-0000-7000-8000-0000000000bb');

    $this->artisan('posts:split-legacy-active')
        ->expectsOutputToContain('Grouped 0 multi-target posts.')
        ->assertSuccessful();

    expect(collect([$published, $failed, $partial])->map(fn (Post $post) => $post->fresh()->post_group_id)->all())->toBe($groups->all());
});

/**
 * A legacy post on two channels whose one item points at a post-owned row,
 * as it is once the library was adopted or the post was edited.
 *
 * @return array{0: Post, 1: Media}
 */
function splitPostWithOwnedMedia(Workspace $workspace, Status $status = Status::Draft): array
{
    $post = legacyAggregatePost($workspace, $workspace->owner, $status, 2);
    $media = Media::factory()->ownedByPost($post)->create(['path' => 'medias/'.Str::uuid().'.jpg']);
    Storage::put($media->path, 'owned bytes');
    $post->forceFill(['media' => [[...MediaItem::fromMedia($media)->toArray(), 'meta' => ['alt_text' => 'Kept alt']]]])->saveQuietly();

    return [$post->fresh(), $media];
}

test('split after the library was adopted gives the copy its own media row and file', function () {
    Storage::fake();
    $workspace = Workspace::factory()->create();
    $asset = Media::factory()->libraryAsset($workspace)->create(['path' => 'medias/'.Str::uuid().'.jpg']);
    Storage::put($asset->path, 'library bytes');
    $post = legacyAggregatePost($workspace, $workspace->owner, Status::Scheduled, 2, [
        'media' => [['id' => $asset->id, 'path' => $asset->path, 'url' => $asset->url, 'type' => 'image', 'mime_type' => 'image/jpeg', 'meta' => ['alt_text' => 'Kept alt']]],
    ]);
    AdoptWorkspaceLibrary::execute($workspace);

    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    $clone = Post::query()->whereKeyNot($post->id)->sole();
    $originalRow = Media::query()->findOrFail(data_get($post->fresh()->media, '0.id'));
    $cloneRow = Media::query()->findOrFail(data_get($clone->media, '0.id'));

    expect($originalRow->post_id)->toBe($post->id)
        ->and($cloneRow->post_id)->toBe($clone->id)
        ->and($cloneRow->path)->not->toBe($originalRow->path)
        ->and(data_get($clone->media, '0.path'))->toBe($cloneRow->path)
        ->and(data_get($clone->media, '0.meta.alt_text'))->toBe('Kept alt')
        ->and(Storage::get($cloneRow->path))->toBe('library bytes')
        ->and(collect(AuditMedia::execute())->every(fn (array $findings): bool => $findings === []))->toBeTrue();

    DeleteOwnedMedia::forPosts([$clone->id]);

    Storage::assertMissing($cloneRow->path);
    Storage::assertExists($originalRow->path);
});

test('a legacy post edited before the split keeps its media and the copy gets its own', function () {
    Storage::fake();
    $workspace = Workspace::factory()->create();
    [$post, $media] = splitPostWithOwnedMedia($workspace);

    $this->artisan('posts:split-legacy-active')->assertSuccessful();
    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    $clone = Post::query()->whereKeyNot($post->id)->sole();
    $cloneRow = Media::query()->findOrFail(data_get($clone->media, '0.id'));

    expect($post->fresh()->media)->toEqual($post->media)
        ->and($media->fresh()->post_id)->toBe($post->id)
        ->and($cloneRow->post_id)->toBe($clone->id)
        ->and($cloneRow->id)->not->toBe($media->id)
        ->and(Storage::get($cloneRow->path))->toBe('owned bytes')
        ->and(Media::query()->count())->toBe(2)
        ->and(collect(AuditMedia::execute())->every(fn (array $findings): bool => $findings === []))->toBeTrue();
});

test('a copy whose media file is gone is not split and the run goes on', function () {
    Storage::fake();
    $workspace = Workspace::factory()->create();
    [$broken, $media] = splitPostWithOwnedMedia($workspace);
    Storage::delete($media->path);
    [$healthy] = splitPostWithOwnedMedia($workspace);

    $this->artisan('posts:split-legacy-active')
        ->expectsOutputToContain("Post {$broken->id} was not split")
        ->assertSuccessful();

    expect($broken->postPlatforms()->count())->toBe(2)
        ->and(Post::query()->count())->toBe(3)
        ->and($healthy->postPlatforms()->count())->toBe(1);
});

test('every item of the original lands on the copy in order, including items without an id and repeated ones', function () {
    Storage::fake();
    $workspace = Workspace::factory()->create();
    [$post, $owned] = splitPostWithOwnedMedia($workspace);
    $asset = Media::factory()->libraryAsset($workspace)->create(['path' => 'medias/'.Str::uuid().'.jpg']);
    $ownedItem = $post->media[0];
    $items = [
        ['url' => 'https://images.example/a.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg'],
        ['url' => 'https://images.example/b.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg'],
        $ownedItem,
        [...$ownedItem, 'meta' => ['alt_text' => 'Second use']],
        ['id' => 'legacy-id', 'path' => 'posts/photo.jpg', 'url' => 'https://cdn.example/photo.jpg', 'type' => 'image'],
        ['id' => $asset->id, 'path' => $asset->path, 'url' => $asset->url, 'type' => 'image'],
    ];
    $post->forceFill(['media' => $items])->saveQuietly();

    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    $clone = Post::query()->whereKeyNot($post->id)->sole();
    $copy = Media::query()->where('post_id', $clone->id)->sole();
    $swap = fn (array $item): array => [...$item, 'id' => $copy->id, 'path' => $copy->path, 'url' => $copy->url];

    expect($post->fresh()->media)->toEqual($items)
        ->and($clone->media)->toEqual([$items[0], $items[1], $swap($items[2]), $swap($items[3]), $items[4], $items[5]])
        ->and($owned->fresh()->post_id)->toBe($post->id);
});

test('a settled multi-target post becomes one post per network with the status of its own target, moving the targets', function () {
    $workspace = Workspace::factory()->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $workspace->user_id,
        'status' => Status::PartiallyPublished,
        'content' => 'Settled caption',
        'published_at' => now()->subDay()->startOfSecond(),
        'created_at' => now()->subDays(2)->startOfSecond(),
    ]);
    $post->labels()->attach($label);
    $note = PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $workspace->user_id]);
    $youtube = PostPlatform::factory()->youtube()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $workspace->id])->id,
        'platform_url' => 'https://youtube.com/watch?v=abc',
    ]);
    $instagram = PostPlatform::factory()->instagram()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $workspace->id])->id,
        'error_message' => 'Media rejected',
    ]);
    $disabled = PostPlatform::factory()->disabled()->create(['post_id' => $post->id, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $workspace->id])->id]);
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $workspace->id, 'post_platform_id' => $youtube->id]);
    Event::fake([PostCreated::class, PostStatusChanged::class]);

    $this->artisan('posts:split-legacy-active')->assertSuccessful();
    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    $youtubePost = $youtube->fresh()->post;
    $instagramPost = $instagram->fresh()->post;

    expect(Post::query()->count())->toBe(2)
        ->and(PostPlatform::query()->count())->toBe(3)
        ->and($youtubePost->id)->not->toBe($instagramPost->id)
        ->and([$youtubePost->id, $instagramPost->id])->toContain($post->id)
        ->and($youtubePost->status)->toBe(Status::Published)
        ->and($instagramPost->status)->toBe(Status::Failed)
        ->and($youtubePost->post_group_id)->not->toBeNull()
        ->and($instagramPost->post_group_id)->toBe($youtubePost->post_group_id)
        ->and($youtube->fresh()->platform_url)->toBe('https://youtube.com/watch?v=abc')
        ->and($instagram->fresh()->error_message)->toBe('Media rejected')
        ->and($publication->fresh()->post_platform_id)->toBe($youtube->id)
        ->and($disabled->fresh()->post_id)->toBe($post->id)
        ->and(Post::query()->where('status', Status::PartiallyPublished)->exists())->toBeFalse();

    foreach ([$youtubePost, $instagramPost] as $split) {
        expect($split->content)->toBe('Settled caption')
            ->and($split->user_id)->toBe($post->user_id)
            ->and($split->published_at?->toIso8601String())->toBe($split->postPlatforms()->enabled()->sole()->published_at?->toIso8601String())
            ->and($split->created_at->equalTo($post->created_at))->toBeTrue()
            ->and($split->labels()->pluck('workspace_labels.id')->all())->toBe([$label->id])
            ->and($split->notes()->pluck('body')->all())->toBe([$note->body])
            ->and($split->postPlatforms()->enabled()->count())->toBe(1);
    }

    Event::assertNotDispatched(PostCreated::class);
    Event::assertNotDispatched(PostStatusChanged::class);
});

test('a partially published post left with one target takes that target status', function () {
    $workspace = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->user_id, 'status' => Status::PartiallyPublished]);
    PostPlatform::factory()->published()->create(['post_id' => $post->id, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $workspace->id])->id]);
    PostPlatform::factory()->disabled()->create(['post_id' => $post->id, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $workspace->id])->id]);

    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    expect($post->fresh()->status)->toBe(Status::Published)
        ->and(Post::query()->count())->toBe(1);
});

test('each settled post takes published_at from its own target and keeps the original created_at, scheduled_at and updated_at', function () {
    Storage::fake();
    $workspace = Workspace::factory()->create();
    $publishedAt = now()->subDays(20)->startOfSecond();
    $createdAt = now()->subDays(22)->startOfSecond();
    $updatedAt = now()->subDays(19)->startOfSecond();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $workspace->user_id,
        'status' => Status::PartiallyPublished,
        'scheduled_at' => $publishedAt,
        'published_at' => $publishedAt,
    ]);
    $media = Media::factory()->ownedByPost($post)->create(['path' => 'medias/'.Str::uuid().'.jpg']);
    Storage::put($media->path, 'bytes');
    $post->forceFill(['media' => [MediaItem::fromMedia($media)->toArray()]])->saveQuietly();
    Post::query()->whereKey($post->id)->toBase()->update(['created_at' => $createdAt, 'updated_at' => $updatedAt]);
    $youtube = PostPlatform::factory()->youtube()->published()->create(['post_id' => $post->id, 'published_at' => $publishedAt, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $workspace->id])->id]);
    $instagram = PostPlatform::factory()->instagram()->failed()->create(['post_id' => $post->id, 'published_at' => null, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $workspace->id])->id]);

    $this->artisan('posts:split-legacy-active')->assertSuccessful();

    $youtubePost = $youtube->fresh()->post;
    $instagramPost = $instagram->fresh()->post;

    expect($youtubePost->published_at->equalTo($publishedAt))->toBeTrue()
        ->and($instagramPost->status)->toBe(Status::Failed)
        ->and($instagramPost->published_at)->toBeNull();

    foreach ([$youtubePost, $instagramPost] as $split) {
        expect($split->created_at->equalTo($createdAt))->toBeTrue()
            ->and($split->scheduled_at->equalTo($publishedAt))->toBeTrue()
            ->and($split->updated_at->equalTo($updatedAt))->toBeTrue();
    }
});

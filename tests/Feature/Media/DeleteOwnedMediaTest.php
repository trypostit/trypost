<?php

declare(strict_types=1);

use App\Actions\Idea\DeleteIdeas;
use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\Media\DeleteOwnedMedia;
use App\Actions\Post\DeletePost;
use App\Actions\RssFeed\DeleteRssFeed;
use App\Actions\RssFeed\SyncRssFeedItems;
use App\Actions\Workspace\DeleteWorkspace;
use App\Actions\Workspace\PurgeWorkspace;
use App\Enums\RssFeed\Format;
use App\Events\PostDeleted;
use App\Jobs\Media\DeleteMediaFiles;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\RssFeed\ParsedRssFeed;
use App\Services\RssFeed\ParsedRssFeedItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();
});

function deleteOwnedMediaStored(Factory $factory): Media
{
    $media = $factory->create();
    Storage::put($media->path, 'bytes');

    return $media;
}

test('forPosts deletes the post rows and their files after commit, and leaves other posts alone', function () {
    $post = Post::factory()->create();
    $other = Post::factory()->create(['workspace_id' => $post->workspace_id]);
    $owned = deleteOwnedMediaStored(Media::factory()->ownedByPost($post));
    $kept = deleteOwnedMediaStored(Media::factory()->ownedByPost($other));

    DB::transaction(function () use ($post, $owned): void {
        DeleteOwnedMedia::forPosts([$post->id]);

        expect(Media::query()->find($owned->id))->toBeNull();
        Storage::assertExists($owned->path);
    });

    expect(Media::query()->find($owned->id))->toBeNull()
        ->and(Media::query()->find($kept->id))->not->toBeNull();
    Storage::assertMissing($owned->path);
    Storage::assertExists($kept->path);
});

test('DeletePost removes the post, its rows and their files and still broadcasts', function () {
    Event::fake([PostDeleted::class]);
    $post = Post::factory()->create();
    $first = deleteOwnedMediaStored(Media::factory()->ownedByPost($post));
    $second = deleteOwnedMediaStored(Media::factory()->ownedByPost($post)->state(['order' => 1]));

    DeletePost::execute($post);

    expect(Post::query()->find($post->id))->toBeNull()
        ->and(Media::query()->count())->toBe(0);
    Storage::assertMissing($first->path);
    Storage::assertMissing($second->path);
    Event::assertDispatched(PostDeleted::class, fn (PostDeleted $event): bool => $event->postId === $post->id);
});

test('a rolled back delete keeps the rows and never runs the file deletion', function () {
    $ran = [];
    Queue::before(function (JobProcessing $event) use (&$ran): void {
        $ran[] = $event->job->resolveName();
    });
    $post = Post::factory()->create();
    $media = deleteOwnedMediaStored(Media::factory()->ownedByPost($post));

    expect(fn () => DB::transaction(function () use ($post): void {
        DeleteOwnedMedia::forPosts([$post->id]);

        throw new RuntimeException('Rolled back');
    }))->toThrow(RuntimeException::class);

    expect(Media::query()->find($media->id))->not->toBeNull();
    Storage::assertExists($media->path);
    expect($ran)->not->toContain(DeleteMediaFiles::class);

    DB::transaction(fn () => DeleteOwnedMedia::forPosts([$post->id]));

    expect($ran)->toContain(DeleteMediaFiles::class);
    Storage::assertMissing($media->path);
});

test('the file job keeps a path another row still references', function () {
    $post = Post::factory()->create();
    $other = Post::factory()->create(['workspace_id' => $post->workspace_id]);
    $media = deleteOwnedMediaStored(Media::factory()->ownedByPost($post));
    Media::factory()->ownedByPost($other)->create(['path' => $media->path]);

    DeleteOwnedMedia::forPosts([$post->id]);

    expect(Media::query()->where('path', $media->path)->count())->toBe(1);
    Storage::assertExists($media->path);
});

test('orphan file cleanup checks paths in one query per chunk and keeps referenced ones', function () {
    $post = Post::factory()->create();
    $referenced = deleteOwnedMediaStored(Media::factory()->ownedByPost($post));
    $orphans = collect(range(1, 3))->map(function (int $index): string {
        $path = "medias/orphan-{$index}.jpg";
        Storage::put($path, 'bytes');

        return $path;
    });

    DB::enableQueryLog();
    DeleteOrphanedMediaFiles::execute([...$orphans->all(), $referenced->path, $referenced, null]);
    $mediaQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'medias'));
    DB::disableQueryLog();

    expect($mediaQueries)->toHaveCount(1);
    Storage::assertExists($referenced->path);
    $orphans->each(fn (string $path) => Storage::assertMissing($path));
});

test('medias.path is indexed', function () {
    $indexed = collect(Schema::getIndexes('medias'))
        ->contains(fn (array $index): bool => $index['columns'] === ['path']);

    expect($indexed)->toBeTrue();
});

test('the file job is retried with backoff', function () {
    $job = new DeleteMediaFiles(['medias/a.jpg']);

    expect($job->tries)->toBe(5)
        ->and($job->backoff())->toBe([10, 60, 300, 900]);
});

test('empty id lists do nothing', function () {
    Queue::fake();
    DB::enableQueryLog();

    DeleteOwnedMedia::forPosts([]);
    DeleteOwnedMedia::forIdeas([]);
    DeleteOwnedMedia::forFeedItems([]);
    DeleteOwnedMedia::forRows([]);

    expect(DB::getQueryLog())->toBe([]);
    Queue::assertNothingPushed();
});

test('forRows deletes exactly the given rows', function () {
    $post = Post::factory()->create();
    $gone = deleteOwnedMediaStored(Media::factory()->ownedByPost($post));
    $kept = deleteOwnedMediaStored(Media::factory()->ownedByPost($post)->state(['order' => 1]));

    DeleteOwnedMedia::forRows([$gone->id]);

    expect($post->ownedMedia()->pluck('id')->all())->toBe([$kept->id]);
    Storage::assertMissing($gone->path);
    Storage::assertExists($kept->path);
});

test('DeleteIdeas removes the ideas rows and files, scoped to the workspace', function () {
    $idea = Idea::factory()->create();
    $foreign = Idea::factory()->create();
    $media = deleteOwnedMediaStored(Media::factory()->ownedByIdea($idea));
    $foreignMedia = deleteOwnedMediaStored(Media::factory()->ownedByIdea($foreign));

    expect(DeleteIdeas::execute($idea->workspace, [$idea->id, $foreign->id]))->toBe(1);

    expect(Idea::query()->pluck('id')->all())->toBe([$foreign->id])
        ->and(Media::query()->pluck('id')->all())->toBe([$foreignMedia->id]);
    Storage::assertMissing($media->path);
    Storage::assertExists($foreignMedia->path);
});

test('DeleteRssFeed removes every item image row and file', function () {
    $feed = RssFeed::factory()->create();
    $images = RssFeedItem::factory()->count(2)->for($feed, 'feed')->create()
        ->map(fn (RssFeedItem $item): Media => deleteOwnedMediaStored(Media::factory()->ownedByFeedItem($item)));

    DeleteRssFeed::execute($feed);

    expect(RssFeed::query()->count())->toBe(0)
        ->and(RssFeedItem::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
    $images->each(fn (Media $image) => Storage::assertMissing($image->path));
});

test('the feed sync prune removes the image of an item that drops out', function () {
    config()->set('trypost.rss_feeds.max_items_per_feed', 1);
    $feed = RssFeed::factory()->create();
    $old = RssFeedItem::factory()->for($feed, 'feed')->create([
        'guid_hash' => RssFeedItem::hashGuid('old'),
        'published_at' => CarbonImmutable::parse('2026-09-01 08:00:00'),
    ]);
    $image = deleteOwnedMediaStored(Media::factory()->ownedByFeedItem($old));

    SyncRssFeedItems::execute($feed, new ParsedRssFeed(Format::Rss, 'Feed', null, [
        new ParsedRssFeedItem(guid: 'new', title: 'New', url: 'https://news.example.com/new', excerpt: 'Excerpt', imageUrl: null, author: null, publishedAt: CarbonImmutable::parse('2026-09-30 08:00:00')),
    ]));

    expect(RssFeedItem::query()->pluck('guid_hash')->all())->toBe([RssFeedItem::hashGuid('new')])
        ->and(Media::query()->find($image->id))->toBeNull();
    Storage::assertMissing($image->path);
});

test('large owner sets are deleted in chunks, one file job per chunk', function () {
    Queue::fake();
    $post = Post::factory()->create();
    DB::table('medias')->insert(collect(range(1, DeleteOwnedMedia::CHUNK + 1))
        ->map(fn (int $index): array => [
            'id' => (string) Str::uuid7(),
            'workspace_id' => $post->workspace_id,
            'post_id' => $post->id,
            'collection' => Media::COLLECTION_MEDIA,
            'type' => 'image',
            'path' => "medias/{$index}.jpg",
            'original_filename' => "{$index}.jpg",
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'order' => $index,
        ])->all());

    DeleteOwnedMedia::forPosts([$post->id]);

    expect(Media::query()->count())->toBe(0);
    Queue::assertPushed(DeleteMediaFiles::class, 2);
    Queue::assertPushed(DeleteMediaFiles::class, fn (DeleteMediaFiles $job): bool => count($job->paths) === DeleteOwnedMedia::CHUNK);
    Queue::assertPushed(DeleteMediaFiles::class, fn (DeleteMediaFiles $job): bool => count($job->paths) === 1);
});

/**
 * @return array{workspace: Workspace, media: Collection<int, Media>}
 */
function deleteOwnedMediaWorkspaceWithOwners(User $owner): array
{
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    $idea = Idea::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id]);
    $item = RssFeedItem::factory()->for(RssFeed::factory()->create(['workspace_id' => $workspace->id]), 'feed')->create();

    return ['workspace' => $workspace, 'media' => collect([
        deleteOwnedMediaStored(Media::factory()->ownedByPost($post)),
        deleteOwnedMediaStored(Media::factory()->ownedByIdea($idea)),
        deleteOwnedMediaStored(Media::factory()->ownedByFeedItem($item)),
    ])];
}

test('DeleteWorkspace removes post, idea and feed owned rows and their files', function () {
    config(['trypost.self_hosted' => true]);
    $owner = User::factory()->create();
    ['workspace' => $workspace, 'media' => $media] = deleteOwnedMediaWorkspaceWithOwners($owner);

    expect(DeleteWorkspace::execute($workspace))->toBeTrue();

    expect(Workspace::query()->find($workspace->id))->toBeNull()
        ->and(Media::query()->count())->toBe(0);
    $media->each(fn (Media $row) => Storage::assertMissing($row->path));
});

test('deleting the owner account removes post, idea and feed owned rows and their files', function () {
    $owner = User::factory()->create();
    ['media' => $media] = deleteOwnedMediaWorkspaceWithOwners($owner);

    $this->actingAs($owner)
        ->delete(route('app.profile.destroy'), ['password' => 'password'])
        ->assertSessionHasNoErrors();

    expect(User::query()->find($owner->id))->toBeNull()
        ->and(Media::query()->count())->toBe(0);
    $media->each(fn (Media $row) => Storage::assertMissing($row->path));
});

test('PurgeWorkspace removes every row the workspace owns and their files', function () {
    $workspace = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    $idea = Idea::factory()->create(['workspace_id' => $workspace->id]);
    $item = RssFeedItem::factory()->for(RssFeed::factory()->create(['workspace_id' => $workspace->id]), 'feed')->create();
    $survivor = deleteOwnedMediaStored(Media::factory()->libraryAsset(Workspace::factory()->create()));

    $rows = collect([
        deleteOwnedMediaStored(Media::factory()->ownedByPost($post)),
        deleteOwnedMediaStored(Media::factory()->ownedByIdea($idea)),
        deleteOwnedMediaStored(Media::factory()->ownedByFeedItem($item)),
        deleteOwnedMediaStored(Media::factory()->temporaryUpload($workspace)),
        deleteOwnedMediaStored(Media::factory()->logo()->for($workspace, 'mediable')),
    ]);

    DB::transaction(fn () => PurgeWorkspace::execute($workspace));

    expect(Workspace::query()->find($workspace->id))->toBeNull()
        ->and(Media::query()->pluck('id')->all())->toBe([$survivor->id]);
    $rows->each(fn (Media $media) => Storage::assertMissing($media->path));
    Storage::assertExists($survivor->path);
});

test('deleting a workspace that still owns media outside PurgeWorkspace fails on the foreign key', function () {
    $workspace = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    Media::factory()->ownedByPost($post)->create();

    expect(fn () => $workspace->delete())->toThrow(QueryException::class);
});

<?php

declare(strict_types=1);

use App\Exceptions\MediaOwnershipViolation;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\RssFeedItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('a media row with two owners cannot be saved', function () {
    $post = Post::factory()->create();
    $idea = Idea::factory()->create(['workspace_id' => $post->workspace_id]);

    expect(fn () => Media::factory()->ownedByPost($post)->create(['idea_id' => $idea->id]))
        ->toThrow(MediaOwnershipViolation::class);
});

test('a media row with no owner cannot be saved', function () {
    $workspace = Workspace::factory()->create();

    expect(fn () => Media::factory()->create([
        'workspace_id' => $workspace->id,
        'mediable_type' => null,
        'mediable_id' => null,
    ]))->toThrow(MediaOwnershipViolation::class);
});

test('a media row without a workspace can only belong to a user', function () {
    $post = Post::factory()->create();
    $user = User::factory()->create();

    expect(fn () => Media::factory()->ownedByPost($post)->create(['workspace_id' => null]))
        ->toThrow(MediaOwnershipViolation::class);

    $avatar = Media::factory()->avatar()->for($user, 'mediable')->create();

    expect($avatar->workspace_id)->toBeNull()
        ->and($avatar->ownerCount())->toBe(1);
});

test('workspace media created through HasMedia carries workspace_id', function () {
    Storage::fake();
    $workspace = Workspace::factory()->create();

    $media = $workspace->addMediaFromPath(base_path('tests/fixtures/1x1.png'), 'image.png', 'logo');

    expect($media->workspace_id)->toBe($workspace->id);
});

test('deleting a post that still owns media fails on the foreign key', function () {
    $post = Post::factory()->create();
    Media::factory()->ownedByPost($post)->create();

    expect(fn () => $post->delete())->toThrow(QueryException::class);
});

test('owned media relations return rows in order', function () {
    $post = Post::factory()->create();
    $second = Media::factory()->ownedByPost($post)->create(['order' => 1]);
    $first = Media::factory()->ownedByPost($post)->create(['order' => 0]);

    $idea = Idea::factory()->create(['workspace_id' => $post->workspace_id]);
    $ideaSecond = Media::factory()->ownedByIdea($idea)->create(['order' => 1]);
    $ideaFirst = Media::factory()->ownedByIdea($idea)->create(['order' => 0]);

    expect($post->ownedMedia->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($idea->ownedMedia->pluck('id')->all())->toBe([$ideaFirst->id, $ideaSecond->id])
        ->and($first->post->is($post))->toBeTrue()
        ->and($ideaFirst->idea->is($idea))->toBeTrue()
        ->and($first->workspace->id)->toBe($post->workspace_id);
});

test('a feed item owns its image row', function () {
    $item = RssFeedItem::factory()->create();
    $image = Media::factory()->ownedByFeedItem($item)->create();

    expect($item->image->is($image))->toBeTrue()
        ->and($image->rssFeedItem->is($item))->toBeTrue()
        ->and($image->workspace_id)->toBe($item->feed->workspace_id)
        ->and($image->collection)->toBe(Media::COLLECTION_MEDIA);
});

test('the migration backfills workspace_id for workspace-owned rows', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $row = fn (string $type, string $id): array => [
        'id' => (string) Str::uuid(),
        'mediable_type' => $type,
        'mediable_id' => $id,
        'collection' => Media::LIBRARY_COLLECTION,
        'type' => 'image',
        'path' => 'medias/'.Str::uuid().'.jpg',
        'original_filename' => 'legacy.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 100,
        'order' => 0,
    ];
    $workspaceRow = $row($workspace->getMorphClass(), $workspace->id);
    $avatarRow = $row($user->getMorphClass(), $user->id);
    DB::table('medias')->insert([$workspaceRow, $avatarRow]);

    (require database_path('migrations/2026_10_01_000001_add_owners_to_medias_table.php'))->backfillWorkspaceIds();

    expect(DB::table('medias')->where('id', $workspaceRow['id'])->value('workspace_id'))->toBe($workspace->id)
        ->and(DB::table('medias')->where('id', $avatarRow['id'])->value('workspace_id'))->toBeNull();
});

test('workspace-owned rows get their workspace_id from the mediable', function () {
    $workspace = Workspace::factory()->create();

    $media = $workspace->media()->create([
        'collection' => Media::COLLECTION_UPLOADS,
        'type' => 'image',
        'path' => 'medias/raw.jpg',
        'original_filename' => 'raw.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 100,
        'order' => 0,
    ]);

    expect($media->workspace_id)->toBe($workspace->id);
});

test('a media row whose workspace differs from its owner cannot be saved', function (string $owner) {
    $factory = match ($owner) {
        'post' => Media::factory()->ownedByPost(Post::factory()->create()),
        'idea' => Media::factory()->ownedByIdea(Idea::factory()->create()),
        'feed item' => Media::factory()->ownedByFeedItem(RssFeedItem::factory()->create()),
        'workspace' => Media::factory()->libraryAsset(Workspace::factory()->create()),
    };
    $other = Workspace::factory()->create();

    expect(fn () => $factory->create(['workspace_id' => $other->id]))->toThrow(MediaOwnershipViolation::class);
})->with(['post', 'idea', 'feed item', 'workspace']);

test('moving a row to another workspace\'s post is rejected', function () {
    $post = Post::factory()->create();
    $media = Media::factory()->ownedByPost($post)->create();

    expect(fn () => $media->update(['post_id' => Post::factory()->create()->id]))
        ->toThrow(MediaOwnershipViolation::class);
});

test('the default factory row is owned by a workspace with a matching workspace_id', function () {
    $workspace = Workspace::factory()->create();

    $default = Media::factory()->create();
    $overridden = Media::factory()->for($workspace, 'mediable')->create();

    expect($default->workspace_id)->toBe($default->mediable_id)
        ->and($overridden->workspace_id)->toBe($workspace->id);
});

test('temporary uploads are scoped by collection and carry an upload token', function () {
    $workspace = Workspace::factory()->create();
    $upload = Media::factory()->temporaryUpload($workspace)->create();
    Media::factory()->libraryAsset($workspace)->create();

    expect(Media::query()->temporaryUploads()->pluck('id')->all())->toBe([$upload->id])
        ->and($upload->upload_token)->toBeString();
});

test('issuing an upload token sets it once', function () {
    $media = Media::factory()->libraryAsset(Workspace::factory()->create())->create(['upload_token' => null]);

    $media->issueUploadToken();
    $token = $media->fresh()->upload_token;
    $media->issueUploadToken();

    expect($token)->toBeString()
        ->and(Str::isUuid($token))->toBeTrue()
        ->and($media->fresh()->upload_token)->toBe($token);
});

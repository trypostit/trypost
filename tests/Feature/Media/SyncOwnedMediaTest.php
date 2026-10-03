<?php

declare(strict_types=1);

use App\Actions\Media\SyncOwnedMedia;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\DeletePost;
use App\Actions\Post\DuplicatePost;
use App\Actions\Post\UpdatePost;
use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Post\MediaAttacher;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostCompositionValidator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->channels = SocialAccount::factory()->linkedin()->count(3)->create(['workspace_id' => $this->workspace->id]);
});

function syncOwnedMediaStored(Factory $factory): Media
{
    $media = $factory->create();
    Storage::put($media->path, "bytes of {$media->id}");

    return $media;
}

/**
 * @param  Collection<int, SocialAccount>  $channels
 * @param  list<array<string, mixed>>  $media
 * @return list<array<string, mixed>>
 */
function syncOwnedMediaDestinations(Collection $channels, array $media = []): array
{
    return $channels->map(fn (SocialAccount $channel): array => [
        'social_account_id' => $channel->id,
        'content_type' => ContentType::LinkedInPost->value,
        ...($media === [] ? [] : ['media' => $media]),
    ])->all();
}

/**
 * @param  list<Media>  $media
 * @return array<string, mixed>
 */
function syncOwnedMediaComposition(Collection $channels, array $media, array $destinations = []): array
{
    return [
        'status' => Status::Draft->value,
        'content' => 'Owned media',
        'media' => array_map(fn (Media $row): array => MediaItem::fromMedia($row)->toArray(), $media),
        'destinations' => $destinations ?: syncOwnedMediaDestinations($channels),
    ];
}

test('one composition to three channels gives every post its own rows and files', function () {
    $uploads = [
        syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace)),
        syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace)),
    ];

    $posts = CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition($this->channels, $uploads));

    $rows = Media::query()->whereNotNull('post_id')->get();

    expect($rows)->toHaveCount(6)
        ->and($rows->pluck('path')->unique())->toHaveCount(6)
        ->and(Media::query()->temporaryUploads()->count())->toBe(0);

    $first = $posts->first();
    expect($first->ownedMedia->pluck('id')->all())->toBe([$uploads[0]->id, $uploads[1]->id])
        ->and($first->ownedMedia->every(fn (Media $row): bool => $row->upload_token === null && $row->collection === Media::COLLECTION_MEDIA))->toBeTrue();

    $posts->skip(1)->each(function (Post $post) use ($uploads): void {
        expect($post->ownedMedia->pluck('meta.copied_from')->all())->toBe([$uploads[0]->id, $uploads[1]->id])
            ->and(collect($post->media)->pluck('id')->all())->toBe($post->ownedMedia->pluck('id')->all());
        $post->ownedMedia->each(fn (Media $row) => Storage::assertExists($row->path));
    });
});

test('syncing a post with the items it already owns changes nothing', function () {
    $post = CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition(
        $this->channels->take(1),
        [syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace))],
    ))->sole();
    $before = $post->ownedMedia()->get(['id', 'path'])->toArray();
    $files = Storage::allFiles();

    MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post->fresh(), $post->media, $batch));

    expect($post->ownedMedia()->get(['id', 'path'])->toArray())->toBe($before)
        ->and(Storage::allFiles())->toEqualCanonicalizing($files);
});

test('updating a post releases the removed row and its file after commit and keeps the alt text edit', function () {
    $post = CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition($this->channels->take(1), [
        syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace)),
        syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace)),
    ]))->sole();
    [$kept, $released] = $post->ownedMedia->all();

    UpdatePost::execute($this->workspace, $post->fresh(), [
        'media' => [[...MediaItem::fromMedia($kept)->toArray(), 'meta' => ['alt_text' => 'A dog']]],
    ]);

    expect($post->fresh()->ownedMedia->pluck('id')->all())->toBe([$kept->id])
        ->and(Media::query()->find($released->id))->toBeNull()
        ->and(data_get($post->fresh()->media, '0.meta.alt_text'))->toBe('A dog');
    Storage::assertMissing($released->path);
    Storage::assertExists($kept->path);
});

test('a duplicate owns new rows and files that survive deleting the original', function () {
    $original = CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition(
        $this->channels->take(1),
        [syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace))],
    ))->sole();

    $copy = DuplicatePost::execute($original->fresh(), $this->user);
    $copyRow = $copy->ownedMedia()->sole();

    expect($copyRow->id)->not->toBe($original->ownedMedia()->sole()->id)
        ->and($copyRow->path)->not->toBe($original->ownedMedia()->sole()->path);

    DeletePost::execute($original->fresh());

    expect(Media::query()->pluck('id')->all())->toBe([$copyRow->id]);
    Storage::assertExists($copyRow->path);
});

test('a failed composition rolls back its rows, keeps the uploads temporary and deletes every copy', function () {
    $uploads = [
        syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace)),
        syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace)),
    ];
    $files = Storage::allFiles();
    $third = $this->channels->last();

    expect(fn () => CreatePosts::execute(
        $this->workspace,
        $this->user,
        syncOwnedMediaComposition($this->channels, $uploads),
        fn () => SocialAccount::query()->whereKey($third->id)->delete(),
    ))->toThrow(ModelNotFoundException::class);

    expect(Post::query()->count())->toBe(0)
        ->and(Media::query()->temporaryUploads()->pluck('id')->sort()->values()->all())
        ->toBe(collect($uploads)->pluck('id')->sort()->values()->all())
        ->and(Media::query()->count())->toBe(2)
        ->and(Storage::allFiles())->toEqualCanonicalizing($files);
});

test('a channel with its own media owns only its override rows', function () {
    $shared = syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace));
    $override = syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace));
    [$channelA, $channelB] = $this->channels->take(2)->all();

    $posts = CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition($this->channels, [$shared], [
        ['social_account_id' => $channelA->id, 'content_type' => ContentType::LinkedInPost->value],
        ['social_account_id' => $channelB->id, 'content_type' => ContentType::LinkedInPost->value, 'media' => [MediaItem::fromMedia($override)->toArray()]],
    ]));
    [$postA, $postB] = $posts->all();

    expect($postA->ownedMedia->pluck('id')->all())->toBe([$shared->id])
        ->and($postB->ownedMedia->pluck('id')->all())->toBe([$override->id]);
});

test('an expired upload token and a foreign id are rejected on their item', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $foreign = Media::factory()->temporaryUpload(Workspace::factory()->create())->create();

    expect(fn () => MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [['upload_token' => (string) Str::uuid()]], $batch)))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['media.0' => [__('posts.errors.media_expired')]]);
        });

    expect(fn () => MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [['id' => $foreign->id]], $batch)))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['media.0' => [__('validation.exists', ['attribute' => 'media'])]]);
        });
});

test('a temporary upload is adopted by upload token', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $upload = syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace));

    MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [['upload_token' => $upload->upload_token, 'meta' => ['alt_text' => 'Hi']]], $batch));

    expect($post->fresh()->ownedMedia->pluck('id')->all())->toBe([$upload->id])
        ->and(data_get($post->fresh()->media, '0.meta.alt_text'))->toBe('Hi')
        ->and($upload->fresh()->upload_token)->toBeNull();
});

test('the composition validator accepts owned rows and uploads of the workspace and rejects a mismatched path', function () {
    $otherPost = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $owned = Media::factory()->ownedByPost($otherPost)->create();
    $upload = Media::factory()->temporaryUpload($this->workspace)->create();
    $composition = fn (array $media): array => [
        'status' => Status::Draft->value,
        'media' => $media,
        'destinations' => syncOwnedMediaDestinations($this->channels->take(1)),
    ];

    $resolved = PostCompositionValidator::validate($this->workspace, $composition([
        MediaItem::fromMedia($owned)->toArray(),
        MediaItem::fromMedia($upload)->toArray(),
    ]));

    expect(array_column($resolved['destinations'][0]['media'], 'id'))->toBe([$owned->id, $upload->id]);

    expect(fn () => PostCompositionValidator::validate($this->workspace, $composition([
        [...MediaItem::fromMedia($owned)->toArray(), 'path' => 'medias/other.jpg'],
    ])))->toThrow(ValidationException::class);
});

test('a library row submitted by id is no longer a source', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $library = syncOwnedMediaStored(Media::factory()->libraryAsset($this->workspace));

    expect(fn () => MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [['id' => $library->id]], $batch)))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['media.0' => [__('validation.exists', ['attribute' => 'media'])]]);
        });

    expect($post->ownedMedia()->count())->toBe(0)
        ->and($library->fresh()->collection)->toBe(Media::LIBRARY_COLLECTION);
    Storage::assertExists($library->path);
});

test('attaching from urls ends with rows owned by the post', function () {
    Http::fake(['https://93.184.216.34/photo.png' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png'])]);
    $post = CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition($this->channels->take(1), []))->sole();

    $result = app(MediaAttacher::class)->attachFromUrls($post, [['url' => 'https://93.184.216.34/photo.png', 'alt' => 'A pixel']]);

    expect($post->ownedMedia()->pluck('id')->all())->toBe([data_get($result, 'attached.0.id')])
        ->and(data_get($post->fresh()->media, '0.meta.alt_text'))->toBe('A pixel')
        ->and(Media::query()->temporaryUploads()->count())->toBe(0);
    Storage::assertExists($post->ownedMedia()->sole()->path);
});

test('recovering an empty legacy draft moves its media to the first post, copies it to the rest and releases the rest', function () {
    $legacy = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'status' => Status::Draft]);
    $owned = syncOwnedMediaStored(Media::factory()->ownedByPost($legacy));
    $unused = syncOwnedMediaStored(Media::factory()->ownedByPost($legacy));
    $legacy->update(['media' => [MediaItem::fromMedia($owned)->toArray(), MediaItem::fromMedia($unused)->toArray()]]);

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        ...syncOwnedMediaComposition($this->channels->take(2), [$owned]),
        'recover_post_id' => $legacy->id,
    ])->assertSessionHasNoErrors();

    $posts = Post::query()->get();
    $mover = $posts->firstWhere('id', $owned->fresh()->post_id);
    $copier = $posts->firstWhere('id', '!=', $mover->id);
    $copy = $copier->ownedMedia()->sole();

    expect($posts)->toHaveCount(2)
        ->and($posts->pluck('id'))->not->toContain($legacy->id)
        ->and($owned->fresh()->path)->toBe($owned->path)
        ->and(collect($mover->media)->pluck('id')->all())->toBe([$owned->id])
        ->and($copy->meta['copied_from'])->toBe($owned->id)
        ->and(collect($copier->media)->pluck('id')->all())->toBe([$copy->id])
        ->and(Media::query()->find($unused->id))->toBeNull()
        ->and(Media::query()->count())->toBe(2);
    Storage::assertExists($owned->path);
    Storage::assertExists($copy->path);
    Storage::assertMissing($unused->path);
});

test('a failed recovery leaves the legacy draft owning its media', function () {
    $legacy = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'status' => Status::Draft]);
    $owned = syncOwnedMediaStored(Media::factory()->ownedByPost($legacy));
    $legacy->update(['media' => [MediaItem::fromMedia($owned)->toArray()]]);
    $missing = Media::factory()->ownedByPost(Post::factory()->create(['workspace_id' => $this->workspace->id]))->create();
    [$channelA, $channelB] = $this->channels->take(2)->all();

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        ...syncOwnedMediaComposition($this->channels->take(2), [$owned], [
            ['social_account_id' => $channelA->id, 'content_type' => ContentType::LinkedInPost->value],
            ['social_account_id' => $channelB->id, 'content_type' => ContentType::LinkedInPost->value, 'media' => [MediaItem::fromMedia($missing)->toArray()]],
        ]),
        'recover_post_id' => $legacy->id,
    ])->assertSessionHasErrors('destinations.1.media.0');

    expect($legacy->fresh())->not->toBeNull()
        ->and($owned->fresh()->post_id)->toBe($legacy->id);
    Storage::assertExists($owned->path);
});

test('a source whose file is gone fails its item and creates no row', function (bool $throws) {
    Storage::fake(null, ['throw' => $throws]);
    $sourcePost = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $source = Media::factory()->ownedByPost($sourcePost)->create();

    expect(fn () => CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition($this->channels->take(1), [$source])))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['media.0' => [__('posts.errors.media_expired')]]);
        });

    expect(Post::query()->pluck('id')->all())->toBe([$sourcePost->id])
        ->and(Media::query()->pluck('id')->all())->toBe([$source->id]);
})->with(['disk returns false' => false, 'disk throws' => true]);

test('an error on a channel\'s own media list names that channel', function () {
    $missing = Media::factory()->ownedByPost(Post::factory()->create(['workspace_id' => $this->workspace->id]))->create();
    $shared = syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace));
    [$channelA, $channelB] = $this->channels->take(2)->all();

    expect(fn () => CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition($this->channels, [$shared], [
        ['social_account_id' => $channelA->id, 'content_type' => ContentType::LinkedInPost->value],
        ['social_account_id' => $channelB->id, 'content_type' => ContentType::LinkedInPost->value, 'media' => [MediaItem::fromMedia($missing)->toArray()]],
    ])))->toThrow(function (ValidationException $exception): void {
        expect(array_keys($exception->errors()))->toBe(['destinations.1.media.0']);
    });
});

test('copies made inside a batch are deleted when an enclosing transaction rolls back', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $source = syncOwnedMediaStored(Media::factory()->ownedByPost(Post::factory()->create(['workspace_id' => $this->workspace->id])));
    $files = Storage::allFiles();

    expect(fn () => DB::transaction(function () use ($post, $source, $files): void {
        MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [['id' => $source->id]], $batch));

        expect(Storage::allFiles())->toHaveCount(count($files) + 1);

        throw new RuntimeException('Outer rollback');
    }))->toThrow(RuntimeException::class);

    expect(Storage::allFiles())->toEqualCanonicalizing($files)
        ->and($post->ownedMedia()->count())->toBe(0);
});

test('a duplicate keeps a legacy item that has no row, like an update does', function () {
    $original = CreatePosts::execute($this->workspace, $this->user, syncOwnedMediaComposition(
        $this->channels->take(1),
        [syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace))],
    ))->sole();
    $dangling = ['id' => (string) Str::uuid(), 'path' => 'medias/gone.jpg', 'url' => 'https://cdn.test/gone.jpg', 'type' => 'image'];
    $original->forceFill(['media' => [...$original->media, $dangling]])->save();

    $copy = DuplicatePost::execute($original->fresh(), $this->user);

    expect(collect($copy->media)->pluck('id')->all())->toHaveCount(2)
        ->and(data_get($copy->media, '1'))->toEqual($dangling)
        ->and($copy->ownedMedia()->count())->toBe(1);
});

test('the same item twice in one list is kept once', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $upload = syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace));
    $second = syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace));

    $final = MediaCopyBatch::run(fn (MediaCopyBatch $batch) => SyncOwnedMedia::execute($post, [
        ['id' => $upload->id],
        ['id' => $upload->id],
        ['upload_token' => $second->upload_token],
        ['upload_token' => $second->upload_token],
    ], $batch));

    expect(array_column($final, 'id'))->toBe([$upload->id, $second->id])
        ->and($post->ownedMedia()->pluck('order', 'id')->all())->toEqual([$upload->id => 0, $second->id => 1]);
});

test('an edit swaps the owned item for its temporary upload at the same index and releases the original after commit', function () {
    $composition = syncOwnedMediaComposition($this->channels->take(1), [
        syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace)),
        syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace)),
    ]);
    $composition['media'][1]['meta'] = ['alt_text' => 'Original alt'];
    $post = CreatePosts::execute($this->workspace, $this->user, $composition)->sole();
    [$first, $original] = $post->ownedMedia->all();
    $edited = syncOwnedMediaStored(Media::factory()->temporaryUpload($this->workspace));
    $submittedOriginal = data_get($post->media, '1');

    expect(data_get($submittedOriginal, 'meta.alt_text'))->toBe('Original alt');

    DB::transaction(function () use ($post, $first, $original, $edited, $submittedOriginal): void {
        UpdatePost::execute($this->workspace, $post->fresh(), [
            'media' => [
                MediaItem::fromMedia($first)->toArray(),
                [...MediaItem::fromMedia($edited)->toArray(), 'meta' => data_get($submittedOriginal, 'meta')],
            ],
        ]);

        expect(Media::query()->find($original->id))->toBeNull();
        Storage::assertExists($original->path);
    });

    $post->refresh();

    expect($post->ownedMedia->pluck('id')->all())->toBe([$first->id, $edited->id])
        ->and(collect($post->media)->pluck('id')->all())->toBe([$first->id, $edited->id])
        ->and($edited->fresh()->upload_token)->toBeNull()
        ->and($edited->fresh()->collection)->toBe(Media::COLLECTION_MEDIA)
        ->and(data_get($post->media, '1.meta.alt_text'))->toBe('Original alt');
    Storage::assertMissing($original->path);
    Storage::assertExists($edited->path);
});

<?php

declare(strict_types=1);

use App\Actions\Media\AdoptWorkspaceLibrary;
use App\Actions\Media\AuditMedia;
use App\Actions\Media\ResolveWorkspaceMedia;
use App\Actions\Media\SyncOwnedMedia;
use App\Enums\Post\Status;
use App\Jobs\Media\AdoptWorkspaceLibraryJob;
use App\Jobs\Media\DeleteMediaFiles;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();

    $this->workspace = Workspace::factory()->create();
});

function adoptLibraryAsset(Workspace $workspace, bool $stored = true): Media
{
    $asset = Media::factory()->libraryAsset($workspace)->create([
        'path' => 'medias/'.Str::uuid().'.jpg',
        'meta' => ['width' => 1080, 'height' => 1080],
    ]);

    if ($stored) {
        Storage::put($asset->path, 'library bytes');
    }

    return $asset;
}

/**
 * @param  array<string, mixed>  $meta
 * @return array<string, mixed>
 */
function adoptLibraryItem(Media $asset, array $meta = []): array
{
    return [
        'id' => $asset->id,
        'path' => $asset->path,
        'url' => $asset->url,
        'type' => 'image',
        'mime_type' => 'image/jpeg',
        ...($meta === [] ? [] : ['meta' => $meta]),
    ];
}

test('a library file referenced by two posts and an idea becomes three owned copies and leaves the library', function () {
    $asset = adoptLibraryAsset($this->workspace);
    $first = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'media' => [adoptLibraryItem($asset, ['alt_text' => 'First alt', 'user_tags' => [['username' => 'ana']]])],
    ]);
    $second = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'media' => [adoptLibraryItem($asset, ['alt_text' => 'Second alt'])],
    ]);
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset)]]);

    $result = AdoptWorkspaceLibrary::execute($this->workspace);

    expect($result)->toBe(['copied' => 3, 'deleted' => 1, 'missing' => 0, 'skipped' => 0, 'deferred' => 0, 'kept' => 0]);

    $owners = [$first->fresh(), $second->fresh(), $idea->fresh()];
    $paths = [];

    foreach ($owners as $owner) {
        $owned = $owner->ownedMedia()->sole();
        $item = $owner->media[0];

        expect($owner->media)->toHaveCount(1)
            ->and($item['id'])->toBe($owned->id)
            ->and($item['path'])->toBe($owned->path)
            ->and($item['url'])->toBe($owned->url)
            ->and($owned->path)->not->toBe($asset->path)
            ->and($owned->collection)->toBe(Media::COLLECTION_MEDIA)
            ->and(Storage::get($owned->path))->toBe('library bytes');

        $paths[] = $owned->path;
    }

    expect(array_unique($paths))->toHaveCount(3)
        ->and($owners[0]->media[0]['meta']['alt_text'])->toBe('First alt')
        ->and($owners[0]->media[0]['meta']['user_tags'])->toEqual([['username' => 'ana']])
        ->and($owners[1]->media[0]['meta']['alt_text'])->toBe('Second alt')
        ->and(data_get($owners[2]->media[0], 'meta.alt_text'))->toBeNull()
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeFalse()
        ->and(Storage::exists($asset->path))->toBeFalse();
});

test('a library file no owner references is deleted with its row', function () {
    $asset = adoptLibraryAsset($this->workspace);
    $otherWorkspaceAsset = adoptLibraryAsset(Workspace::factory()->create());

    $result = AdoptWorkspaceLibrary::execute($this->workspace);

    expect($result)->toBe(['copied' => 0, 'deleted' => 1, 'missing' => 0, 'skipped' => 0, 'deferred' => 0, 'kept' => 0])
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeFalse()
        ->and(Storage::exists($asset->path))->toBeFalse()
        ->and(Media::query()->whereKey($otherWorkspaceAsset->id)->exists())->toBeTrue()
        ->and(Storage::exists($otherWorkspaceAsset->path))->toBeTrue();
});

test('a publishing post is skipped, the job releases itself and the library stays', function () {
    Queue::fake();

    $asset = adoptLibraryAsset($this->workspace);
    $publishing = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Publishing,
        'media' => [adoptLibraryItem($asset)],
    ]);
    $draft = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset)]]);

    $job = (new AdoptWorkspaceLibraryJob($this->workspace))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(delay: AdoptWorkspaceLibraryJob::RETRY_PUBLISHING_AFTER);

    expect($publishing->fresh()->media[0]['id'])->toBe($asset->id)
        ->and($publishing->fresh()->ownedMedia()->exists())->toBeFalse()
        ->and($draft->fresh()->ownedMedia()->count())->toBe(1)
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and(Storage::exists($asset->path))->toBeTrue();
});

test('a job with nothing left to wait for is not released', function () {
    adoptLibraryAsset($this->workspace);

    $job = (new AdoptWorkspaceLibraryJob($this->workspace))->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();
    expect(Media::query()->where('collection', Media::LIBRARY_COLLECTION)->exists())->toBeFalse();
});

test('an item whose library file is gone is counted as missing, left as is and reported by the audit', function () {
    $gone = adoptLibraryAsset($this->workspace, stored: false);
    $kept = adoptLibraryAsset($this->workspace);
    $items = [adoptLibraryItem($gone, ['alt_text' => 'Lost']), adoptLibraryItem($kept)];
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => $items]);

    $result = AdoptWorkspaceLibrary::execute($this->workspace);

    $post->refresh();
    $owned = $post->ownedMedia()->sole();

    expect($result)->toBe(['copied' => 1, 'deleted' => 2, 'missing' => 1, 'skipped' => 0, 'deferred' => 0, 'kept' => 0])
        ->and($post->media)->toHaveCount(2)
        ->and($post->media[0])->toEqual($items[0])
        ->and($post->media[1]['id'])->toBe($owned->id)
        ->and(Media::query()->where('collection', Media::LIBRARY_COLLECTION)->exists())->toBeFalse();

    expect(AuditMedia::execute()['json_drift'])->toContain([
        'owner_type' => 'post',
        'owner_id' => $post->id,
        'media_id' => $gone->id,
        'problem' => 'json_without_row',
    ]);

    $this->artisan('media:audit')->assertExitCode(1);
});

test('running the adoption twice changes nothing the second time', function () {
    $asset = adoptLibraryAsset($this->workspace);
    adoptLibraryAsset($this->workspace);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset, ['alt_text' => 'Alt'])]]);
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset)]]);

    AdoptWorkspaceLibrary::execute($this->workspace);

    $rows = Media::query()->orderBy('id')->get()->map->only(['id', 'path', 'post_id', 'idea_id', 'collection'])->all();
    $files = Storage::allFiles();
    $postMedia = $post->fresh()->media;
    $ideaMedia = $idea->fresh()->media;

    $result = AdoptWorkspaceLibrary::execute($this->workspace);

    expect($result)->toBe(['copied' => 0, 'deleted' => 0, 'missing' => 0, 'skipped' => 0, 'deferred' => 0, 'kept' => 0])
        ->and(Media::query()->orderBy('id')->get()->map->only(['id', 'path', 'post_id', 'idea_id', 'collection'])->all())->toEqual($rows)
        ->and(Storage::allFiles())->toEqual($files)
        ->and($post->fresh()->media)->toEqual($postMedia)
        ->and($idea->fresh()->media)->toEqual($ideaMedia);

    $this->artisan('media:adopt-library --force')
        ->expectsOutput('No library media to adopt.')
        ->assertExitCode(0);
});

test('the command dispatches one job per workspace with library media', function () {
    Queue::fake();

    adoptLibraryAsset($this->workspace);
    adoptLibraryAsset($this->workspace);
    $other = Workspace::factory()->create();
    adoptLibraryAsset($other);
    Workspace::factory()->create();

    $this->artisan('media:adopt-library --force')->assertExitCode(0);

    Queue::assertPushed(AdoptWorkspaceLibraryJob::class, 2);
    Queue::assertPushed(AdoptWorkspaceLibraryJob::class, fn (AdoptWorkspaceLibraryJob $job): bool => $job->workspace->is($this->workspace));
    Queue::assertPushed(AdoptWorkspaceLibraryJob::class, fn (AdoptWorkspaceLibraryJob $job): bool => $job->workspace->is($other));
});

test('the dry run prints the counts and dispatches nothing', function () {
    Queue::fake([AdoptWorkspaceLibraryJob::class, DeleteMediaFiles::class]);

    $referenced = adoptLibraryAsset($this->workspace);
    $gone = adoptLibraryAsset($this->workspace, stored: false);
    $orphan = adoptLibraryAsset($this->workspace);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($referenced), adoptLibraryItem($gone)]]);
    Idea::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($referenced)]]);

    expect(AdoptWorkspaceLibrary::plan($this->workspace))->toBe(['library' => 3, 'references' => 2, 'orphans' => 1, 'missing' => 1, 'publishing' => 0]);

    $this->artisan('media:adopt-library --dry-run')
        ->expectsTable(
            ['Workspace', 'Name', 'Library rows', 'References to copy', 'Orphans to delete', 'Missing files', 'Publishing posts (defer)'],
            [[$this->workspace->id, $this->workspace->name, 3, 2, 1, 1, 0]],
        )
        ->assertExitCode(0);

    Queue::assertNothingPushed();

    expect(Media::query()->whereKey([$referenced->id, $gone->id, $orphan->id])->count())->toBe(3)
        ->and(Storage::exists($orphan->path))->toBeTrue();
});

arch('adoption copies library rows itself, without the save path that stops accepting them')
    ->expect(AdoptWorkspaceLibrary::class)
    ->not->toUse([ResolveWorkspaceMedia::class, SyncOwnedMedia::class]);

test('the library is not deleted while an owner item still points at it', function () {
    $asset = adoptLibraryAsset($this->workspace);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset)]]);

    Post::saving(function (Post $saving): void {
        $saving->media = $saving->getOriginal('media');
    });

    expect(fn () => AdoptWorkspaceLibrary::execute($this->workspace))
        ->toThrow(RuntimeException::class, "still referenced by post {$post->id}");

    expect($post->fresh()->media[0]['id'])->toBe($asset->id)
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and(Storage::exists($asset->path))->toBeTrue();
});

test('the reference guard passes only once no post or idea item points at the library', function () {
    $asset = adoptLibraryAsset($this->workspace);
    $idea = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset)]]);

    expect(fn () => AdoptWorkspaceLibrary::assertLibraryUnreferenced($this->workspace))
        ->toThrow(RuntimeException::class, "still referenced by idea {$idea->id}");

    $idea->update(['media' => []]);

    AdoptWorkspaceLibrary::assertLibraryUnreferenced($this->workspace);

    expect(Media::query()->whereKey($asset->id)->exists())->toBeTrue();
});

test('a workspace whose library files are all missing is skipped loudly instead of emptied', function () {
    Log::spy();

    $first = adoptLibraryAsset($this->workspace, stored: false);
    $second = adoptLibraryAsset($this->workspace, stored: false);
    $item = adoptLibraryItem($first);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [$item]]);

    $result = AdoptWorkspaceLibrary::execute($this->workspace);

    expect($result)->toBe(['copied' => 0, 'deleted' => 0, 'missing' => 0, 'skipped' => 0, 'deferred' => 0, 'kept' => 0])
        ->and(Media::query()->whereKey([$first->id, $second->id])->count())->toBe(2)
        ->and($post->fresh()->media)->toEqual([$item]);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['workspace_id'] === $this->workspace->id);
});

test('a run released for a publishing post deletes the library on the next run', function () {
    $asset = adoptLibraryAsset($this->workspace);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Publishing,
        'media' => [adoptLibraryItem($asset, ['alt_text' => 'Kept'])],
    ]);

    expect(AdoptWorkspaceLibrary::execute($this->workspace)['skipped'])->toBe(1)
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue();

    $post->update(['status' => Status::Published, 'published_at' => now()]);

    $job = (new AdoptWorkspaceLibraryJob($this->workspace))->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    $owned = $post->fresh()->ownedMedia()->sole();

    expect($post->fresh()->media[0])->toMatchArray(['id' => $owned->id, 'path' => $owned->path, 'meta' => ['alt_text' => 'Kept']])
        ->and(Storage::get($owned->path))->toBe('library bytes')
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeFalse()
        ->and(Storage::exists($asset->path))->toBeFalse();
});

test('a failing owner leaves its items, its rows and the library intact, and the rerun completes', function () {
    $first = adoptLibraryAsset($this->workspace);
    $second = adoptLibraryAsset($this->workspace);
    $items = [adoptLibraryItem($first), adoptLibraryItem($second)];
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => $items]);
    $filesBefore = Storage::allFiles();

    $created = 0;
    $failing = true;
    Media::creating(function () use (&$created, &$failing): void {
        if ($failing && ++$created === 2) {
            throw new RuntimeException('Simulated failure on the second copy.');
        }
    });

    expect(fn () => AdoptWorkspaceLibrary::execute($this->workspace))->toThrow(RuntimeException::class, 'Simulated failure');

    expect($post->fresh()->media)->toEqual($items)
        ->and($post->fresh()->ownedMedia()->exists())->toBeFalse()
        ->and(Media::query()->whereKey([$first->id, $second->id])->count())->toBe(2)
        ->and(Storage::allFiles())->toEqual($filesBefore);

    $failing = false;

    expect(AdoptWorkspaceLibrary::execute($this->workspace))->toBe(['copied' => 2, 'deleted' => 2, 'missing' => 0, 'skipped' => 0, 'deferred' => 0, 'kept' => 0])
        ->and($post->fresh()->ownedMedia()->count())->toBe(2);
});

test('a copy left behind by a killed run is reused, not orphaned', function () {
    $asset = adoptLibraryAsset($this->workspace);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset)]]);
    $leftover = AdoptWorkspaceLibrary::COPY_PREFIX."{$post->id}-{$asset->id}.jpg";
    Storage::put($leftover, 'partial bytes');

    AdoptWorkspaceLibrary::execute($this->workspace);

    $owned = $post->fresh()->ownedMedia()->sole();

    expect($owned->path)->toBe($leftover)
        ->and(Storage::get($leftover))->toBe('library bytes')
        ->and(Storage::allFiles())->toEqual([$leftover]);
});

test('owners past the time budget are deferred to the next attempt and the library stays', function () {
    $asset = adoptLibraryAsset($this->workspace);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset)]]);
    Idea::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($asset)]]);

    $result = AdoptWorkspaceLibrary::execute($this->workspace, now()->subSecond());

    expect($result)->toBe(['copied' => 0, 'deleted' => 0, 'missing' => 0, 'skipped' => 0, 'deferred' => 2, 'kept' => 0])
        ->and($post->fresh()->media[0]['id'])->toBe($asset->id)
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue();

    $job = (new AdoptWorkspaceLibraryJob($this->workspace))->withFakeQueueInteractions();
    $job->budgetSeconds = -1;
    $job->handle();

    $job->assertReleased(delay: AdoptWorkspaceLibraryJob::RETRY_DEFERRED_AFTER);
});

test('the job retries by time, not attempts, and its unique lock expires', function () {
    $job = new AdoptWorkspaceLibraryJob($this->workspace);

    expect($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(5)->getTimestamp())
        ->and($job->uniqueFor)->toBeGreaterThan(AdoptWorkspaceLibraryJob::RETRY_HOURS * 3600)
        ->and(property_exists($job, 'tries'))->toBeFalse();
});

test('a save that lands once the library is locked is seen by the guard and nothing is deleted', function () {
    $asset = adoptLibraryAsset($this->workspace);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => []]);
    $injected = false;

    DB::listen(function (QueryExecuted $query) use (&$injected, $post, $asset): void {
        if ($injected || ! str_contains(strtolower($query->sql), 'for update') || ! in_array(Media::LIBRARY_COLLECTION, $query->bindings, true)) {
            return;
        }

        $injected = true;
        $post->update(['media' => [adoptLibraryItem($asset)]]);
    });

    expect(fn () => AdoptWorkspaceLibrary::execute($this->workspace))
        ->toThrow(RuntimeException::class, "still referenced by post {$post->id}");

    expect($injected)->toBeTrue()
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and(Storage::exists($asset->path))->toBeTrue();
});

test('a library row another workspace still references is kept and logged', function () {
    Log::spy();

    $shared = adoptLibraryAsset($this->workspace);
    $orphan = adoptLibraryAsset($this->workspace);
    $other = Workspace::factory()->create();
    $foreignPost = Post::factory()->create(['workspace_id' => $other->id, 'media' => [adoptLibraryItem($shared)]]);

    expect(AdoptWorkspaceLibrary::execute($this->workspace))->toBe(['copied' => 0, 'deleted' => 1, 'missing' => 0, 'skipped' => 0, 'deferred' => 0, 'kept' => 1])
        ->and(Media::query()->whereKey($shared->id)->exists())->toBeTrue()
        ->and(Storage::exists($shared->path))->toBeTrue()
        ->and(Media::query()->whereKey($orphan->id)->exists())->toBeFalse()
        ->and($foreignPost->fresh()->media[0]['id'])->toBe($shared->id);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['media_id'] === $shared->id
        && $context['owners'] === ["post:{$foreignPost->id}"]);
});

test('the command counts workspaces whose job is still queued instead of dispatching them again', function () {
    Queue::fake([AdoptWorkspaceLibraryJob::class]);

    adoptLibraryAsset($this->workspace);
    $other = Workspace::factory()->create();
    adoptLibraryAsset($other);
    (new UniqueLock(Cache::store()))->acquire(new AdoptWorkspaceLibraryJob($other));

    $this->artisan('media:adopt-library --force')
        ->expectsOutput('Dispatched 1 library adoption job(s); 1 already queued.')
        ->assertExitCode(0);

    Queue::assertPushed(AdoptWorkspaceLibraryJob::class, 1);
    Queue::assertPushed(AdoptWorkspaceLibraryJob::class, fn (AdoptWorkspaceLibraryJob $job): bool => $job->workspace->is($this->workspace));
});

test('the dry run counts publishing posts that would defer the deletion', function () {
    $asset = adoptLibraryAsset($this->workspace);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => Status::Publishing, 'media' => [adoptLibraryItem($asset)]]);

    expect(AdoptWorkspaceLibrary::plan($this->workspace))->toBe(['library' => 1, 'references' => 1, 'orphans' => 0, 'missing' => 0, 'publishing' => 1]);
});

test('a deadline that passes before the final step defers the deletion', function () {
    $asset = adoptLibraryAsset($this->workspace);

    expect(AdoptWorkspaceLibrary::execute($this->workspace, now()->subSecond()))->toBe(['copied' => 0, 'deleted' => 0, 'missing' => 0, 'skipped' => 0, 'deferred' => 1, 'kept' => 0])
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue();
});

test('foreign references are found in one pass over posts and ideas of every other workspace', function () {
    $sharedByPost = adoptLibraryAsset($this->workspace);
    $sharedByIdea = adoptLibraryAsset($this->workspace);
    $ownOnly = adoptLibraryAsset($this->workspace);
    $other = Workspace::factory()->create();
    $otherAsset = adoptLibraryAsset($other);
    $owned = Media::factory()->ownedByPost(Post::factory()->create(['workspace_id' => $this->workspace->id]))->create();
    $foreignPost = Post::factory()->create(['workspace_id' => $other->id, 'media' => [adoptLibraryItem($sharedByPost), adoptLibraryItem($otherAsset), adoptLibraryItem($owned)]]);
    $foreignIdea = Idea::factory()->create(['workspace_id' => $other->id, 'media' => [adoptLibraryItem($sharedByIdea)]]);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'media' => [adoptLibraryItem($ownOnly)]]);

    expect(AdoptWorkspaceLibrary::foreignReferences())->toEqual([
        $this->workspace->id => [
            $sharedByPost->id => ["post:{$foreignPost->id}"],
            $sharedByIdea->id => ["idea:{$foreignIdea->id}"],
        ],
    ])
        ->and(AdoptWorkspaceLibrary::foreignReferences([$other->id]))->toBe([]);
});

test('the foreign reference scan gives up once its deadline passes', function () {
    $asset = adoptLibraryAsset($this->workspace);
    Post::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'media' => [adoptLibraryItem($asset)]]);

    expect(AdoptWorkspaceLibrary::foreignReferences(null, now()->subSecond()))->toBeNull()
        ->and(AdoptWorkspaceLibrary::foreignReferences(null, now()->addMinute()))->toHaveKey($this->workspace->id);
});

test('a precomputed foreign reference map is trusted, so the locked delete scans no other workspace', function () {
    $kept = adoptLibraryAsset($this->workspace);
    $orphan = adoptLibraryAsset($this->workspace);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = AdoptWorkspaceLibrary::execute($this->workspace, now()->addMinute(), [$kept->id => ['post:elsewhere']]);

    expect($result)->toBe(['copied' => 0, 'deleted' => 1, 'missing' => 0, 'skipped' => 0, 'deferred' => 0, 'kept' => 1])
        ->and(Media::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Media::query()->whereKey($orphan->id)->exists())->toBeFalse()
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, '!=') || str_contains($sql, '<>')))->toBeEmpty();
});

test('a foreign reference scan cut short by the deadline defers the deletion instead of reading as empty', function () {
    $this->freezeTime();
    $shared = adoptLibraryAsset($this->workspace);
    $foreignPost = Post::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'media' => [adoptLibraryItem($shared)]]);
    $deadline = now()->addMinute();
    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, 'workspace_id') && str_contains($query->sql, 'not null') && ! str_contains($query->sql, 'workspace_id" =') && ! str_contains($query->sql, 'workspace_id` =')) {
            $this->travel(2)->minutes();
        }
    });

    expect(AdoptWorkspaceLibrary::execute($this->workspace, $deadline))->toBe(['copied' => 0, 'deleted' => 0, 'missing' => 0, 'skipped' => 0, 'deferred' => 1, 'kept' => 0])
        ->and(Media::query()->whereKey($shared->id)->exists())->toBeTrue()
        ->and($foreignPost->fresh()->media[0]['id'])->toBe($shared->id);
});

test('the command computes foreign references once, caches them and hands each job its slice', function () {
    Queue::fake([AdoptWorkspaceLibraryJob::class]);

    $shared = adoptLibraryAsset($this->workspace);
    $other = Workspace::factory()->create();
    adoptLibraryAsset($other);
    $foreignPost = Post::factory()->create(['workspace_id' => $other->id, 'media' => [adoptLibraryItem($shared)]]);

    $this->artisan('media:adopt-library --force')->assertExitCode(0);

    Queue::assertPushed(AdoptWorkspaceLibraryJob::class, fn (AdoptWorkspaceLibraryJob $job): bool => $job->workspace->is($this->workspace)
        && $job->foreignReferences === [$shared->id => ["post:{$foreignPost->id}"]]);
    Queue::assertPushed(AdoptWorkspaceLibraryJob::class, fn (AdoptWorkspaceLibraryJob $job): bool => $job->workspace->is($other)
        && $job->foreignReferences === []);
    expect(Cache::get(AdoptWorkspaceLibrary::FOREIGN_REFERENCES_CACHE_KEY))->toBe([$this->workspace->id => [$shared->id => ["post:{$foreignPost->id}"]]]);

    $this->travel(AdoptWorkspaceLibraryJob::UNIQUE_FOR_SECONDS + 1)->seconds();

    expect(Cache::has(AdoptWorkspaceLibrary::FOREIGN_REFERENCES_CACHE_KEY))->toBeFalse();

    $this->artisan('media:adopt-library --force')->assertExitCode(0);

    expect(Cache::has(AdoptWorkspaceLibrary::FOREIGN_REFERENCES_CACHE_KEY))->toBeTrue();

    Media::query()->where('collection', Media::LIBRARY_COLLECTION)->get()->each->delete();

    $this->artisan('media:adopt-library --force')->expectsOutput('No library media to adopt.')->assertExitCode(0);

    expect(Cache::has(AdoptWorkspaceLibrary::FOREIGN_REFERENCES_CACHE_KEY))->toBeFalse();
});

test('a job that runs out of retries logs an error instead of giving up silently', function () {
    Log::spy();

    $job = new AdoptWorkspaceLibraryJob($this->workspace);
    $job->failed(new RuntimeException('timed out'));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['workspace_id'] === $this->workspace->id
        && $context['exception'] === 'timed out');
});
